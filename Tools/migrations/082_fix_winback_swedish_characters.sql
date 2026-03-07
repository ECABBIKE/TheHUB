-- ============================================================================
-- Migration 082: Fix Swedish characters in winback questions
-- ============================================================================
-- The seed data in migration 021 was missing å, ä, ö characters.
-- This migration updates existing questions with correct Swedish text.
-- ============================================================================

-- Q1: Vad är huvudorsaken till att du inte tävlade?
UPDATE winback_questions
SET question_text = 'Vad är huvudorsaken till att du inte tävlade 2025?',
    options = '["Tidsbrist / livssituation","Skada eller sjukdom","Kostnad (anmälningsavgifter, resor, utrustning)","Brist på motivation / intresse","Flyttat / för långt till tävlingar","Saknade träningskompisar / team","Event passade inte min nivå","Bytte sport / aktivitet","Annat"]'
WHERE question_text LIKE 'Vad ar huvudorsaken%';

-- Q2: Vad skulle få dig att tävla igen?
UPDATE winback_questions
SET question_text = 'Vad skulle få dig att tävla igen?',
    options = '["Lägre anmälningsavgifter","Fler tävlingar i min region","Bättre klasser för min nivå","Tävla med vänner / klubbkompisar","Kortare / enklare format","Bättre prispengar / premier","Roligare banor / venues","Bättre stämning / community","Inget speciellt - planerar redan återkomma","Annat"]'
WHERE question_text LIKE 'Vad skulle fa dig%';

-- Q3: Hur troligt är det att du tävlar?
UPDATE winback_questions
SET question_text = 'Hur troligt är det att du tävlar 2026?'
WHERE question_text LIKE 'Hur troligt ar%';

-- Q4: Vilken disciplin lockar dig mest?
UPDATE winback_questions
SET options = '["Enduro","Downhill","XC / Cross Country","Gravel","Öppen för allt"]'
WHERE question_text LIKE 'Vilken disciplin%';

-- Q5: Något annat du vill dela med dig av?
UPDATE winback_questions
SET question_text = 'Något annat du vill dela med dig av?'
WHERE question_text LIKE 'Nagot annat%';
