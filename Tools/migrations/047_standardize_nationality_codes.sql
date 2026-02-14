-- Migration 047: Standardize nationality codes to ISO 3166-1 alpha-3
-- Fixes legacy/incorrect codes used in earlier imports

-- DEN -> DNK (Danmark)
UPDATE riders SET nationality = 'DNK' WHERE nationality = 'DEN';

-- GER -> DEU (Tyskland)
UPDATE riders SET nationality = 'DEU' WHERE nationality = 'GER';

-- SUI -> CHE (Schweiz)
UPDATE riders SET nationality = 'CHE' WHERE nationality = 'SUI';

-- NED -> NLD (Nederländerna)
UPDATE riders SET nationality = 'NLD' WHERE nationality = 'NED';
