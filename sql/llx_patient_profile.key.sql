ALTER TABLE llx_patient_profile ADD UNIQUE INDEX uk_patient_profile_soc (fk_soc);
ALTER TABLE llx_patient_profile ADD UNIQUE INDEX uk_patient_profile_card (card_no);
ALTER TABLE llx_patient_profile ADD INDEX idx_patient_profile_idhash (id_number_hash);
ALTER TABLE llx_patient_profile ADD INDEX idx_patient_profile_entity (entity);
