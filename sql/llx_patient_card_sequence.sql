-- modPatient: monthly card-number sequence (HZ-YYYYMM-NNNN), same pattern as
-- chinadoc's shipment sequence. InnoDB row lock is held by the caller's
-- transaction. Kept on module disable. One sequence across entities.
CREATE TABLE IF NOT EXISTS llx_patient_card_sequence (
	ref_prefix	varchar(16) NOT NULL,
	last_value	bigint NOT NULL DEFAULT 0,
	PRIMARY KEY (ref_prefix)
) ENGINE=innodb;
