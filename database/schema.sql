-- database/schema.sql — منصة الترصد الذكي لحمى الضنك (DengueWatch SD)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS sessions, forecasts, forecast_runs, alerts, cases, users, facilities, localities, states, settings;

CREATE TABLE states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(10) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE localities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    INDEX (state_id),
    FOREIGN KEY (state_id) REFERENCES states(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- المنشأة الصحية: مستشفى / مركز صحي / وحدة صحية (تتبع محلية)
CREATE TABLE facilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    locality_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    type ENUM('hospital','health_center','health_unit') NOT NULL DEFAULT 'health_unit',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (locality_id),
    FOREIGN KEY (locality_id) REFERENCES localities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- المستخدمون: طبيب وحدة (doctor) أو مدير إدارة بوزارة الصحة (manager)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('doctor','manager') NOT NULL,
    facility_id INT NULL,
    license_no VARCHAR(50) NULL,
    status ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (facility_id), INDEX (role, status),
    FOREIGN KEY (facility_id) REFERENCES facilities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- الحالات: لا يُخزَّن اسم المريض عمداً؛ يكفي رمز الحالة والعمر والجنس (حماية الخصوصية)
CREATE TABLE cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_code VARCHAR(30) NOT NULL UNIQUE,
    facility_id INT NOT NULL,
    doctor_id INT NOT NULL,
    locality_id INT NOT NULL,
    state_id INT NOT NULL,
    report_date DATE NOT NULL,
    onset_date DATE NOT NULL,
    age_years TINYINT UNSIGNED NOT NULL,
    sex ENUM('male','female') NOT NULL,
    classification ENUM('suspected','probable','confirmed') NOT NULL DEFAULT 'suspected',
    test_type ENUM('none','rapid','ns1','igm','pcr') NOT NULL DEFAULT 'none',
    severity ENUM('mild','warning_signs','severe') NOT NULL DEFAULT 'mild',
    hospitalized TINYINT(1) NOT NULL DEFAULT 0,
    outcome ENUM('under_treatment','recovered','died') NOT NULL DEFAULT 'under_treatment',
    notes VARCHAR(300) NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_state_date (state_id, report_date),
    INDEX idx_loc_date (locality_id, report_date),
    INDEX idx_fac_date (facility_id, report_date),
    INDEX idx_doc_date (doctor_id, report_date),
    INDEX idx_date (report_date),
    FOREIGN KEY (facility_id) REFERENCES facilities(id),
    FOREIGN KEY (doctor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- تنبيهات التفشي: واحد لكل (محلية، أسبوع)
CREATE TABLE alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    locality_id INT NOT NULL,
    week_start DATE NOT NULL,
    cases_count INT NOT NULL,
    prev_count INT NOT NULL,
    level ENUM('warning','outbreak') NOT NULL,
    reason VARCHAR(200) NOT NULL,
    status ENUM('open','acknowledged','closed') NOT NULL DEFAULT 'open',
    handled_by INT NULL,
    handled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loc_week (locality_id, week_start),
    INDEX (status),
    FOREIGN KEY (locality_id) REFERENCES localities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
    `key` VARCHAR(60) PRIMARY KEY,
    `value` VARCHAR(100) NOT NULL,
    label VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE forecast_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at DATETIME NOT NULL,
    run_by INT NULL,
    model_name VARCHAR(120) NOT NULL,
    horizon_weeks TINYINT NOT NULL,
    n_train_weeks INT NOT NULL,
    mae_model DECIMAL(10,2) NULL,
    mae_naive DECIMAL(10,2) NULL,
    note VARCHAR(300) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE forecasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    state_id INT NOT NULL,
    week_start DATE NOT NULL,
    predicted DECIMAL(10,1) NOT NULL,
    lower_bound DECIMAL(10,1) NOT NULL,
    upper_bound DECIMAL(10,1) NOT NULL,
    risk ENUM('low','medium','high') NOT NULL,
    INDEX (run_id, state_id),
    FOREIGN KEY (run_id) REFERENCES forecast_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (state_id) REFERENCES states(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`,`value`,label) VALUES
 ('alert_warning_cases','10','حد «تحذير»: عدد حالات المحلية في الأسبوع'),
 ('alert_outbreak_cases','25','حد «تفشٍّ»: عدد حالات المحلية في الأسبوع'),
 ('alert_growth_ratio','2','تحذير إذا تضاعف العدد عن الأسبوع السابق بهذه النسبة'),
 ('alert_growth_min_cases','5','أقل عدد حالات لتفعيل قاعدة النمو'),
 ('forecast_horizon','4','أسابيع التنبؤ القادمة');

-- جدول الجلسات (يستعمله معالج الجلسات في نسخة النشر على Vercel)
CREATE TABLE sessions (
    id          VARCHAR(128) NOT NULL PRIMARY KEY,
    data        MEDIUMTEXT   NOT NULL,
    user_id     INT          NULL,
    created_at  INT UNSIGNED NOT NULL,
    last_access INT UNSIGNED NOT NULL,
    KEY idx_sessions_last_access (last_access)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
