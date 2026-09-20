-- modPatient dictionaries, edited through Home > Setup > Dictionaries.
-- Plain integer PK without auto increment: admin/dict.php computes MAX(rowid)+1.

CREATE TABLE llx_c_patient_department(
	rowid	integer PRIMARY KEY,
	pos		tinyint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(128),
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;

CREATE TABLE llx_c_patient_id_type(
	rowid	integer PRIMARY KEY,
	pos		tinyint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(128),
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;

CREATE TABLE llx_c_patient_allergy_type(
	rowid	integer PRIMARY KEY,
	pos		tinyint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(128),
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;

CREATE TABLE llx_c_patient_doctor_title(
	rowid	integer PRIMARY KEY,
	pos		tinyint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(128),
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;
