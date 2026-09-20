-- modPatient: structured allergy history. fk_product is optional and lets
-- modPrescription block by exact product match before falling back to name.

CREATE TABLE llx_patient_allergy(
	rowid			integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity			integer DEFAULT 1 NOT NULL,
	fk_patient		integer NOT NULL,
	allergy_type	varchar(16) DEFAULT 'OTHER' NOT NULL,
	fk_product		integer DEFAULT NULL,
	name			varchar(128) NOT NULL,
	severity		smallint DEFAULT 1 NOT NULL,
	reaction		varchar(255) DEFAULT NULL,
	status			smallint DEFAULT 1 NOT NULL,
	fk_user_creat	integer DEFAULT NULL,
	date_creation	datetime NOT NULL,
	tms				timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
