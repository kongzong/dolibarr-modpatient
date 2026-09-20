<?php
/* Copyright (C) 2026 modPatient contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Monthly clinic card numbers (HZ-YYYYMM-NNNN), reserved inside the caller's
 * database transaction. Same locking pattern as modChinaDoc's shipment
 * numbering: the InnoDB row lock taken with FOR UPDATE lives until the caller
 * commits or rolls back, so two registrations of the same month serialize.
 * A call outside a transaction is a read-only preview and reserves nothing.
 */
class PatientCardNumbering
{
	/** Prefix shape: HZ-YYYYMM- */
	const PREFIX_PATTERN = '/^HZ-[0-9]{6}-$/D';

	/** @var DoliDB */
	private $db;

	/** @param DoliDB $db Database connection of the registration transaction */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Prefix for a timestamp (defaults to now).
	 *
	 * @param	int|null	$timestamp	Unix timestamp
	 * @return	string					HZ-YYYYMM-
	 */
	public static function prefixFor($timestamp = null)
	{
		return 'HZ-'.date('Ym', $timestamp === null ? time() : (int) $timestamp).'-';
	}

	/**
	 * @param string $prefix Monthly prefix, for example HZ-202609-
	 * @return string Card number (a preview unless a caller transaction is open)
	 * @throws RuntimeException On any failure; never returns an invalid number.
	 */
	public function nextReference($prefix)
	{
		$step = 'checking the numbering context';
		try {
			if (!is_object($this->db) || $this->db->type !== 'mysqli' || !preg_match(self::PREFIX_PATTERN, $prefix)) {
				throw new RuntimeException('MySQL/MariaDB and a monthly card prefix are required');
			}

			$reserve = $this->db->transaction_opened > 0;
			$table = $this->db->prefix().'patient_card_sequence';
			$escapedPrefix = $this->db->escape($prefix);
			$lock = $reserve ? ' FOR UPDATE' : '';

			if ($reserve) {
				$step = 'locking the monthly sequence';
				// The upsert also serializes the first two registrations of a new month.
				// No BEGIN/COMMIT here: the lock must survive until the caller saves.
				$this->query("INSERT INTO ".$table." (ref_prefix, last_value) VALUES ('".$escapedPrefix."', 0)"
					." ON DUPLICATE KEY UPDATE last_value = last_value");
			}

			$step = 'reading the monthly sequence';
			$result = $this->query("SELECT last_value FROM ".$table." WHERE ref_prefix = '".$escapedPrefix."'".$lock);
			$counter = $this->db->fetch_object($result);
			if (!$counter && ($reserve || $this->db->num_rows($result) !== 0)) {
				throw new RuntimeException('Cannot read the monthly sequence row');
			}
			$lastValue = $this->sequenceValue($counter ? $counter->last_value : null);
			$this->db->free($result);

			$step = 'reading existing card numbers';
			// Seed from history so an upgrade or a manually imported batch never
			// produces a duplicate; the unique index on card_no is the last guard.
			$result = $this->query("SELECT MAX(CAST(SUBSTRING(card_no, ".(strlen($prefix) + 1).") AS UNSIGNED)) AS maxseq"
				." FROM ".$this->db->prefix()."patient_profile WHERE card_no LIKE '".$escapedPrefix."%'".$lock);
			$history = $this->db->fetch_object($result);
			if (!$history || !property_exists($history, 'maxseq')) {
				throw new RuntimeException('Cannot read existing card numbers');
			}
			$lastValue = max($lastValue, $this->sequenceValue($history->maxseq));
			$this->db->free($result);
			if ($lastValue >= PHP_INT_MAX) {
				throw new RuntimeException('Card sequence exhausted');
			}
			$nextValue = $lastValue + 1;

			if ($reserve) {
				$step = 'reserving the card number';
				$this->query("UPDATE ".$table." SET last_value = ".$nextValue." WHERE ref_prefix = '".$escapedPrefix."'");
			}

			return $prefix.sprintf('%04d', $nextValue);
		} catch (Throwable $error) {
			// Unwind every transaction level so the caller cannot keep a half-saved patient.
			$this->rollback();
			$message = 'modPatient could not generate a card number while '.$step.'. '
				.'Retry the registration; after upgrading, re-enable the module to install its sequence table.';
			dol_syslog($message, LOG_ERR);
			throw new RuntimeException($message, 0, $error);
		}
	}

	/** @param string $sql SQL statement
	 *  @return mixed Successful result
	 */
	private function query($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RuntimeException('Card numbering query failed');
		}
		return $result;
	}

	/** @param mixed $value Database integer or NULL for an empty month
	 *  @return int Valid nonnegative sequence
	 */
	private function sequenceValue($value)
	{
		if ($value === null) {
			return 0;
		}
		$text = (string) $value;
		$maximum = (string) PHP_INT_MAX;
		if (!preg_match('/^[0-9]+$/D', $text) || strlen($text) > strlen($maximum)
			|| (strlen($text) === strlen($maximum) && strcmp($text, $maximum) > 0)) {
			throw new RuntimeException('Invalid or exhausted card sequence');
		}
		return (int) $text;
	}

	/** Fully unwind DoliDB's transaction depth; an inner rollback alone is a no-op. */
	private function rollback()
	{
		if (!is_object($this->db)) {
			return;
		}
		try {
			while ($this->db->transaction_opened > 0) {
				if (!$this->db->rollback()) {
					$this->db->close();
					break;
				}
			}
		} catch (Throwable $error) {
			try {
				$this->db->close();
			} catch (Throwable $ignored) {
				// Preserve the original numbering failure for the caller.
			}
		}
	}
}
