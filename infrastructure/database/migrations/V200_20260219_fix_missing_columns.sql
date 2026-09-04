-- Migration V37: Fix Critical Missing Columns (Nexus Logs)
-- Date: 2026-02-19
-- Description: Adds missing columns that caused 500 errors in Licenciadas and Doctor Harmony.

-- 1. Add CPF to Students (For Licenciadas file naming/uniqueness)
ALTER TABLE students 
ADD COLUMN cpf VARCHAR(14) DEFAULT NULL AFTER state;

-- 2. Add Doctor Harmony Response Fields (For AI Clinical Cases)
ALTER TABLE ai_clinical_cases 
ADD COLUMN doctor_harmony_response TEXT DEFAULT NULL AFTER ana_response,
ADD COLUMN mentor_feedback TEXT DEFAULT NULL AFTER doctor_harmony_response;

-- Verification
-- SELECT cpf FROM students LIMIT 1;
-- SELECT doctor_harmony_response FROM ai_clinical_cases LIMIT 1;
