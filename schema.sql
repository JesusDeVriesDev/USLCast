-- Tabla de usuarios
CREATE TABLE users (
  id SERIAL PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  name TEXT NOT NULL,
  locale CHAR(2) NOT NULL DEFAULT 'es',
  role SMALLINT NOT NULL DEFAULT 1, -- 1: user, 2: referee, 3: hoster, 4:admin
  created_at TIMESTAMPTZ DEFAULT now(),
  updated_at TIMESTAMPTZ DEFAULT now()
);

select * from users;
select * from meets;
select * from platforms;
select * from divisions;
select * from competitors;
select * from competitor_divisions;
select * from weight_classes;
select * from attempts;

-- Tabla de competiciones (meets)
CREATE TABLE meets (
  id SERIAL PRIMARY KEY,
  organizer_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  federation TEXT,
  meet_date DATE NOT NULL,
  units TEXT CHECK (units IN ('kg','lb')) DEFAULT 'kg',
  timezone TEXT NOT NULL DEFAULT 'America/Bogota',
  locale CHAR(2) NOT NULL DEFAULT 'es',
  contact_email TEXT,
  max_entries INTEGER,
  entry_fee NUMERIC(10,2),
  additional_division_fee NUMERIC(10,2),
  stripe_public_key TEXT,
  stripe_private_key TEXT,
  registration_open BOOLEAN DEFAULT FALSE,
  description TEXT,
  disclaimer TEXT,
  website_url TEXT,
  settings JSONB DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ DEFAULT now(),
  updated_at TIMESTAMPTZ DEFAULT now()
);

-- Tabla de plataformas (varios escenarios por meet)
CREATE TABLE platforms (
  id SERIAL PRIMARY KEY,
  meet_id INTEGER NOT NULL REFERENCES meets(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  plate_colors JSONB DEFAULT '{}'::jsonb
);

-- Tabla de divisiones (categorías de premiación)
CREATE TABLE divisions (
  id SERIAL PRIMARY KEY,
  meet_id INTEGER NOT NULL REFERENCES meets(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  gender CHAR(1) CHECK (gender IN ('M','F')),
  type TEXT CHECK (type IN ('Raw','Equipped')),
  scoring_method TEXT, -- Total, DOTS, IPF, etc.
  min_weight NUMERIC(5,2),
  max_weight NUMERIC(5,2),
  division_code TEXT,
  hidden_on_board BOOLEAN DEFAULT FALSE
);

-- Tabla de competidores (lifters)
CREATE TABLE competitors (
  id SERIAL PRIMARY KEY,
  meet_id INTEGER NOT NULL REFERENCES meets(id) ON DELETE CASCADE,
  platform_id INTEGER REFERENCES platforms(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  email TEXT,
  dob DATE,
  gender CHAR(1) CHECK (gender IN ('M','F')),
  team TEXT,
  membership_number TEXT,
  phone TEXT,
  address JSONB DEFAULT '{}'::jsonb,           -- street, city, state, zip
  emergency_contact JSONB DEFAULT '{}'::jsonb, -- name, phone
  body_weight NUMERIC(5,2),
  rack_height JSONB DEFAULT '{}'::jsonb,       -- squat, bench, deadlift
  attempts JSONB DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ DEFAULT now()
);
-- Tabla de intentos por levantamiento (detalle del performance)
CREATE TABLE attempts (
  id SERIAL PRIMARY KEY,
  competitor_id INTEGER NOT NULL REFERENCES competitors(id) ON DELETE CASCADE,
  lift_type TEXT CHECK (lift_type IN ('Squat','Bench','Deadlift')),
  attempt_number SMALLINT CHECK (attempt_number BETWEEN 1 AND 3),
  weight NUMERIC(5,2),
  success BOOLEAN,
  is_record BOOLEAN DEFAULT FALSE,
  record_type TEXT CHECK (record_type IN ('State','Regional','National','World')),
  referee_calls JSONB DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ DEFAULT now()
);

-- Tabla de árbitros (referees)
CREATE TABLE referees (
  id SERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  meet_id INTEGER NOT NULL REFERENCES meets(id) ON DELETE CASCADE,
  role TEXT CHECK (role IN ('Head','Side-Left','Side-Right')),
  platform_id INTEGER REFERENCES platforms(id),
  created_at TIMESTAMPTZ DEFAULT now()
);

-- Resultados finales (resumen de cada competidor)
CREATE TABLE results (
  id SERIAL PRIMARY KEY,
  competitor_id INTEGER NOT NULL REFERENCES competitors(id) ON DELETE CASCADE,
  total NUMERIC(6,2),
  place INTEGER,
  points NUMERIC(6,2),
  disqualified BOOLEAN DEFAULT FALSE,
  best_squat NUMERIC(6,2),
  best_bench NUMERIC(6,2),
  best_deadlift NUMERIC(6,2),
  created_at TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE competitor_divisions (
  id SERIAL PRIMARY KEY,
  competitor_id INTEGER NOT NULL REFERENCES competitors(id) ON DELETE CASCADE,
  division_id INTEGER NOT NULL REFERENCES divisions(id) ON DELETE CASCADE,
  raw_or_equipped TEXT CHECK (raw_or_equipped IN ('Raw','Equipped')),
  declared_weight_class TEXT
);

-- Agregar esta tabla a tu esquema
CREATE TABLE weight_classes (
  id SERIAL PRIMARY KEY,
  division_id INTEGER NOT NULL REFERENCES divisions(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  min_weight NUMERIC(5,2),
  max_weight NUMERIC(5,2),
  division_code TEXT,
  created_at TIMESTAMPTZ DEFAULT now()
);

-- Agregar columnas para lifts y competition_type
ALTER TABLE divisions 
ADD COLUMN lifts JSONB DEFAULT '{"squat": true, "bench": true, "deadlift": true}'::jsonb,
ADD COLUMN competition_type TEXT CHECK (competition_type IN ('Powerlifting','Push/Pull','Bench','Deadlift'));

-- Actualizar divisiones existentes con valores por defecto
UPDATE divisions SET 
  lifts = '{"squat": true, "bench": true, "deadlift": true}'::jsonb,
  competition_type = 'Powerlifting'
WHERE lifts IS NULL OR competition_type IS NULL;

ALTER TABLE competitors 
ADD COLUMN lot_number INTEGER,
ADD COLUMN session INTEGER,
ADD COLUMN flight TEXT;

-- Índices recomendados
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_meets_organizer ON meets(organizer_id);
CREATE INDEX idx_competitors_meet ON competitors(meet_id);
CREATE INDEX idx_attempts_competitor ON attempts(competitor_id);
CREATE INDEX idx_results_competitor ON results(competitor_id);
CREATE INDEX idx_weight_classes_division ON weight_classes(division_id);