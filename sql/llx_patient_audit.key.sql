ALTER TABLE llx_patient_audit ADD INDEX idx_patient_audit_patient (fk_patient);
ALTER TABLE llx_patient_audit ADD INDEX idx_patient_audit_user (fk_user);
ALTER TABLE llx_patient_audit ADD INDEX idx_patient_audit_date (date_creation);
