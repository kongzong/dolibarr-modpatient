-- modPatient: medical profile of a patient. The patient itself is an
-- individual thirdparty (llx_societe.fk_typent = 8 TE_PRIVATE); this table
-- extends it 1:1 (fk_soc unique). ID number: dolEncrypt ciphertext + SHA-256
-- hash for exact lookup + last 4 digits for masked display. Plaintext is
-- never stored (spec §4.1).

CREATE TABLE llx_patient_profile(
	rowid			integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity			integer DEFAULT 1 NOT NULL,
	fk_soc			integer NOT NULL,
	card_no			varchar(32) NOT NULL,
	id_type			varchar(16) DEFAULT NULL,
	id_number_enc	text DEFAULT NULL,
	id_number_hash	varchar(64) DEFAULT NULL,
	id_number_tail	varchar(4) DEFAULT NULL,
	gender			varchar(1) DEFAULT 'U' NOT NULL,
	birth_date		date DEFAULT NULL,
	blood_type		varchar(4) DEFAULT NULL,
	phone_alt		varchar(32) DEFAULT NULL,
	emergency_name	varchar(64) DEFAULT NULL,
	emergency_phone	varchar(32) DEFAULT NULL,
	history_note	text DEFAULT NULL,
	note_private	text DEFAULT NULL,
	status			smallint DEFAULT 1 NOT NULL,
	fk_user_creat	integer DEFAULT NULL,
	fk_user_modif	integer DEFAULT NULL,
	date_creation	datetime NOT NULL,
	tms				timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
