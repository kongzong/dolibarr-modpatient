ALTER TABLE llx_patient_allergy ADD INDEX idx_patient_allergy_patient (fk_patient);
ALTER TABLE llx_patient_allergy ADD INDEX idx_patient_allergy_product (fk_product);
