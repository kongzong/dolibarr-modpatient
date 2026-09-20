-- modPatient: doctor = Dolibarr user; department and title come from the
-- module dictionaries. Scheduling belongs to modClinicBook, not here.

CREATE TABLE llx_patient_doctor(
	rowid			integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity			integer DEFAULT 1 NOT NULL,
	fk_user			integer NOT NULL,
	fk_department	integer DEFAULT NULL,
	title_code		varchar(16) DEFAULT NULL,
	status			smallint DEFAULT 1 NOT NULL,
	date_creation	datetime NOT NULL,
	tms				timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
