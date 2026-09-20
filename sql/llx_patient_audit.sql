-- modPatient: append-only audit trail (spec §2.5 / §4.2). The module offers
-- no UPDATE/DELETE path and remove() keeps this table.

CREATE TABLE llx_patient_audit(
	rowid			integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity			integer DEFAULT 1 NOT NULL,
	fk_patient		integer NOT NULL,
	action			varchar(32) NOT NULL,
	fk_user			integer DEFAULT NULL,
	ip				varchar(64) DEFAULT NULL,
	detail			text DEFAULT NULL,
	date_creation	datetime NOT NULL
) ENGINE=innodb;
