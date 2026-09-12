
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE SCHEMA IF NOT EXISTS erp;
SET search_path TO erp, public;

-- =========================
-- TENANT / SEGURIDAD
-- =========================
CREATE TABLE tenant (
  tenant_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name VARCHAR(150) NOT NULL,
  legal_name VARCHAR(200),
  tax_id VARCHAR(30),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE app_user (
  user_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  email VARCHAR(180) NOT NULL,
  password_hash VARCHAR(255),
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  phone VARCHAR(30),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  last_login_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (tenant_id,email)
);

CREATE TABLE role (
  role_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  UNIQUE (tenant_id,name)
);

CREATE TABLE permission (
  permission_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  code VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255)
);

CREATE TABLE user_role (
  user_id UUID NOT NULL REFERENCES app_user(user_id) ON DELETE CASCADE,
  role_id UUID NOT NULL REFERENCES role(role_id) ON DELETE CASCADE,
  PRIMARY KEY(user_id,role_id)
);

CREATE TABLE role_permission (
  role_id UUID NOT NULL REFERENCES role(role_id) ON DELETE CASCADE,
  permission_id UUID NOT NULL REFERENCES permission(permission_id) ON DELETE CASCADE,
  PRIMARY KEY(role_id,permission_id)
);

-- =========================
-- CATÁLOGOS
-- =========================
CREATE TABLE currency (
  currency_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  code CHAR(3) NOT NULL UNIQUE,
  name VARCHAR(60) NOT NULL,
  symbol VARCHAR(10) NOT NULL,
  decimals SMALLINT NOT NULL DEFAULT 2,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
);

CREATE TABLE exchange_rate (
  exchange_rate_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  currency_from_id UUID NOT NULL REFERENCES currency(currency_id),
  currency_to_id UUID NOT NULL REFERENCES currency(currency_id),
  rate NUMERIC(18,8) NOT NULL CHECK(rate > 0),
  effective_at TIMESTAMPTZ NOT NULL,
  source VARCHAR(80),
  UNIQUE(currency_from_id,currency_to_id,effective_at)
);

-- =========================
-- CRM
-- =========================
CREATE TABLE crm_company (
  company_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  legal_name VARCHAR(200) NOT NULL,
  tax_id VARCHAR(30),
  email VARCHAR(180),
  phone VARCHAR(40),
  address VARCHAR(255),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE crm_contact (
  contact_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  company_id UUID REFERENCES crm_company(company_id),
  user_id UUID REFERENCES app_user(user_id),
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(180),
  phone VARCHAR(40),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE crm_lead (
  lead_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  company_id UUID REFERENCES crm_company(company_id),
  contact_id UUID REFERENCES crm_contact(contact_id),
  source VARCHAR(80),
  stage VARCHAR(50) NOT NULL DEFAULT 'NEW',
  estimated_value NUMERIC(18,2),
  currency_id UUID REFERENCES currency(currency_id),
  assigned_to UUID REFERENCES app_user(user_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE crm_opportunity (
  opportunity_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  company_id UUID REFERENCES crm_company(company_id),
  contact_id UUID REFERENCES crm_contact(contact_id),
  name VARCHAR(180) NOT NULL,
  stage VARCHAR(50) NOT NULL DEFAULT 'QUALIFICATION',
  probability NUMERIC(5,2),
  amount NUMERIC(18,2),
  currency_id UUID REFERENCES currency(currency_id),
  owner_id UUID REFERENCES app_user(user_id),
  expected_close_date DATE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =========================
-- CURSOS / LMS
-- =========================
CREATE TABLE course (
  course_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  code VARCHAR(50),
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(220) NOT NULL,
  short_description VARCHAR(500),
  description TEXT,
  level VARCHAR(40),
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  price NUMERIC(18,2) NOT NULL DEFAULT 0,
  currency_id UUID REFERENCES currency(currency_id),
  duration_hours NUMERIC(8,2),
  published_at TIMESTAMPTZ,
  created_by UUID REFERENCES app_user(user_id),
  updated_by UUID REFERENCES app_user(user_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id,slug)
);

CREATE TABLE course_version (
  course_version_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  course_id UUID NOT NULL REFERENCES course(course_id) ON DELETE CASCADE,
  version_no INTEGER NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  snapshot_json JSONB,
  created_by UUID REFERENCES app_user(user_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(course_id,version_no)
);

CREATE TABLE course_section (
  section_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  course_id UUID NOT NULL REFERENCES course(course_id) ON DELETE CASCADE,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  position INTEGER NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  UNIQUE(course_id,position)
);

CREATE TABLE course_resource (
  resource_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  section_id UUID NOT NULL REFERENCES course_section(section_id) ON DELETE CASCADE,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  file_url TEXT,
  external_url TEXT,
  position INTEGER NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
);

CREATE TABLE activity (
  activity_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  section_id UUID NOT NULL REFERENCES course_section(section_id) ON DELETE CASCADE,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  position INTEGER NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  due_at TIMESTAMPTZ,
  passing_score NUMERIC(6,2),
  max_attempts INTEGER,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE course_teacher (
  course_id UUID NOT NULL REFERENCES course(course_id) ON DELETE CASCADE,
  teacher_id UUID NOT NULL REFERENCES app_user(user_id),
  role VARCHAR(40) NOT NULL DEFAULT 'TEACHER',
  PRIMARY KEY(course_id,teacher_id)
);

-- =========================
-- EVALUACIONES
-- =========================
CREATE TABLE question_bank (
  bank_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  course_id UUID REFERENCES course(course_id),
  name VARCHAR(180) NOT NULL,
  description TEXT,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE question (
  question_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  bank_id UUID NOT NULL REFERENCES question_bank(bank_id) ON DELETE CASCADE,
  type VARCHAR(40) NOT NULL,
  statement TEXT NOT NULL,
  points NUMERIC(8,2) NOT NULL DEFAULT 1,
  difficulty VARCHAR(30),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
);

CREATE TABLE question_option (
  option_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  question_id UUID NOT NULL REFERENCES question(question_id) ON DELETE CASCADE,
  option_text TEXT NOT NULL,
  is_correct BOOLEAN NOT NULL DEFAULT false,
  position INTEGER NOT NULL
);

CREATE TABLE quiz_config (
  activity_id UUID PRIMARY KEY REFERENCES activity(activity_id) ON DELETE CASCADE,
  bank_id UUID REFERENCES question_bank(bank_id),
  question_count INTEGER,
  randomize_questions BOOLEAN NOT NULL DEFAULT false,
  randomize_options BOOLEAN NOT NULL DEFAULT false,
  time_limit_minutes INTEGER,
  attempts_allowed INTEGER
);

CREATE TABLE quiz_attempt (
  attempt_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  activity_id UUID NOT NULL REFERENCES activity(activity_id),
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  attempt_no INTEGER NOT NULL,
  started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  submitted_at TIMESTAMPTZ,
  score NUMERIC(8,2),
  status VARCHAR(30) NOT NULL DEFAULT 'IN_PROGRESS',
  UNIQUE(activity_id,user_id,attempt_no)
);

CREATE TABLE quiz_answer (
  answer_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  attempt_id UUID NOT NULL REFERENCES quiz_attempt(attempt_id) ON DELETE CASCADE,
  question_id UUID NOT NULL REFERENCES question(question_id),
  selected_option_id UUID REFERENCES question_option(option_id),
  answer_text TEXT,
  is_correct BOOLEAN,
  points_awarded NUMERIC(8,2)
);

CREATE TABLE assignment (
  assignment_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  activity_id UUID NOT NULL REFERENCES activity(activity_id),
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  submitted_at TIMESTAMPTZ,
  file_url TEXT,
  text_answer TEXT,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  grade NUMERIC(8,2),
  feedback TEXT,
  graded_by UUID REFERENCES app_user(user_id),
  graded_at TIMESTAMPTZ
);

CREATE TABLE survey_response (
  survey_response_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  activity_id UUID NOT NULL REFERENCES activity(activity_id),
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  response_json JSONB NOT NULL,
  submitted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =========================
-- SCORM
-- =========================
CREATE TABLE scorm_package (
  scorm_package_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  course_id UUID REFERENCES course(course_id),
  name VARCHAR(200) NOT NULL,
  version VARCHAR(30),
  package_url TEXT NOT NULL,
  manifest_json JSONB,
  status VARCHAR(30) NOT NULL DEFAULT 'IMPORTED',
  imported_by UUID REFERENCES app_user(user_id),
  imported_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE scorm_attempt (
  scorm_attempt_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  scorm_package_id UUID NOT NULL REFERENCES scorm_package(scorm_package_id),
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  completed_at TIMESTAMPTZ,
  score NUMERIC(8,2),
  completion_status VARCHAR(40),
  success_status VARCHAR(40),
  suspend_data TEXT
);

-- =========================
-- MATRÍCULAS / PROGRESO
-- =========================
CREATE TABLE enrollment (
  enrollment_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  course_id UUID NOT NULL REFERENCES course(course_id),
  order_item_id UUID,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  enrolled_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  start_at TIMESTAMPTZ,
  end_at TIMESTAMPTZ,
  completion_percent NUMERIC(6,2) NOT NULL DEFAULT 0,
  completed_at TIMESTAMPTZ,
  UNIQUE(user_id,course_id)
);

CREATE TABLE activity_progress (
  progress_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  enrollment_id UUID NOT NULL REFERENCES enrollment(enrollment_id) ON DELETE CASCADE,
  activity_id UUID NOT NULL REFERENCES activity(activity_id),
  status VARCHAR(30) NOT NULL DEFAULT 'NOT_STARTED',
  progress_percent NUMERIC(6,2) NOT NULL DEFAULT 0,
  started_at TIMESTAMPTZ,
  completed_at TIMESTAMPTZ,
  UNIQUE(enrollment_id,activity_id)
);

CREATE TABLE course_attendance (
  attendance_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  enrollment_id UUID NOT NULL REFERENCES enrollment(enrollment_id),
  session_date DATE NOT NULL,
  status VARCHAR(30) NOT NULL,
  marked_by UUID REFERENCES app_user(user_id),
  notes TEXT,
  UNIQUE(enrollment_id,session_date)
);

-- =========================
-- CERTIFICADOS
-- =========================
CREATE TABLE certificate_template (
  template_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  name VARCHAR(150) NOT NULL,
  template_url TEXT,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
);

CREATE TABLE certificate (
  certificate_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  enrollment_id UUID NOT NULL REFERENCES enrollment(enrollment_id),
  template_id UUID REFERENCES certificate_template(template_id),
  verification_code VARCHAR(100) NOT NULL UNIQUE,
  qr_payload TEXT NOT NULL,
  issued_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  status VARCHAR(30) NOT NULL DEFAULT 'VALID',
  revoked_at TIMESTAMPTZ,
  revoke_reason TEXT
);

-- =========================
-- PAQUETES
-- =========================
CREATE TABLE course_package (
  package_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  name VARCHAR(180) NOT NULL,
  description TEXT,
  price NUMERIC(18,2) NOT NULL,
  currency_id UUID REFERENCES currency(currency_id),
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE package_course (
  package_id UUID NOT NULL REFERENCES course_package(package_id) ON DELETE CASCADE,
  course_id UUID NOT NULL REFERENCES course(course_id),
  PRIMARY KEY(package_id,course_id)
);

-- =========================
-- PROMOCIONES / CUPONES
-- =========================
CREATE TABLE promotion (
  promotion_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  name VARCHAR(180) NOT NULL,
  type VARCHAR(30) NOT NULL,
  value NUMERIC(18,4) NOT NULL,
  currency_id UUID REFERENCES currency(currency_id),
  starts_at TIMESTAMPTZ,
  ends_at TIMESTAMPTZ,
  usage_limit INTEGER,
  used_count INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT'
);

CREATE TABLE coupon (
  coupon_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  promotion_id UUID NOT NULL REFERENCES promotion(promotion_id) ON DELETE CASCADE,
  code VARCHAR(80) NOT NULL,
  usage_limit INTEGER,
  used_count INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  UNIQUE(promotion_id,code)
);

CREATE TABLE promotion_course (
  promotion_id UUID NOT NULL REFERENCES promotion(promotion_id) ON DELETE CASCADE,
  course_id UUID NOT NULL REFERENCES course(course_id),
  PRIMARY KEY(promotion_id,course_id)
);

-- =========================
-- B2C / B2B
-- =========================
CREATE TABLE sales_order (
  order_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  user_id UUID REFERENCES app_user(user_id),
  company_id UUID REFERENCES crm_company(company_id),
  currency_id UUID NOT NULL REFERENCES currency(currency_id),
  subtotal NUMERIC(18,2) NOT NULL DEFAULT 0,
  discount NUMERIC(18,2) NOT NULL DEFAULT 0,
  tax NUMERIC(18,2) NOT NULL DEFAULT 0,
  total NUMERIC(18,2) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  coupon_id UUID REFERENCES coupon(coupon_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  paid_at TIMESTAMPTZ
);

CREATE TABLE sales_order_item (
  order_item_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id UUID NOT NULL REFERENCES sales_order(order_id) ON DELETE CASCADE,
  course_id UUID REFERENCES course(course_id),
  package_id UUID REFERENCES course_package(package_id),
  description VARCHAR(250) NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 1 CHECK(quantity>0),
  unit_price NUMERIC(18,2) NOT NULL,
  discount NUMERIC(18,2) NOT NULL DEFAULT 0,
  total NUMERIC(18,2) NOT NULL
);

ALTER TABLE enrollment
  ADD CONSTRAINT fk_enrollment_order_item
  FOREIGN KEY(order_item_id) REFERENCES sales_order_item(order_item_id);

CREATE TABLE b2b_quote (
  quote_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  company_id UUID NOT NULL REFERENCES crm_company(company_id),
  contact_id UUID REFERENCES crm_contact(contact_id),
  currency_id UUID NOT NULL REFERENCES currency(currency_id),
  quote_number VARCHAR(50) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  valid_until DATE,
  subtotal NUMERIC(18,2) NOT NULL DEFAULT 0,
  discount NUMERIC(18,2) NOT NULL DEFAULT 0,
  tax NUMERIC(18,2) NOT NULL DEFAULT 0,
  total NUMERIC(18,2) NOT NULL DEFAULT 0,
  created_by UUID REFERENCES app_user(user_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id,quote_number)
);

CREATE TABLE b2b_quote_item (
  quote_item_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  quote_id UUID NOT NULL REFERENCES b2b_quote(quote_id) ON DELETE CASCADE,
  course_id UUID REFERENCES course(course_id),
  package_id UUID REFERENCES course_package(package_id),
  quantity INTEGER NOT NULL CHECK(quantity>0),
  unit_price NUMERIC(18,2) NOT NULL,
  total NUMERIC(18,2) NOT NULL
);

CREATE TABLE license (
  license_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  quote_id UUID REFERENCES b2b_quote(quote_id),
  company_id UUID NOT NULL REFERENCES crm_company(company_id),
  course_id UUID REFERENCES course(course_id),
  package_id UUID REFERENCES course_package(package_id),
  quantity INTEGER NOT NULL CHECK(quantity>0),
  assigned_quantity INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'AVAILABLE'
);

CREATE TABLE license_assignment (
  license_assignment_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  license_id UUID NOT NULL REFERENCES license(license_id) ON DELETE CASCADE,
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  assigned_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  revoked_at TIMESTAMPTZ,
  UNIQUE(license_id,user_id)
);

-- =========================
-- PAGOS / FINANZAS
-- =========================
CREATE TABLE payment_method (
  payment_method_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  code VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  provider VARCHAR(100),
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  UNIQUE(tenant_id,code)
);

CREATE TABLE payment (
  payment_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id UUID NOT NULL REFERENCES sales_order(order_id),
  payment_method_id UUID NOT NULL REFERENCES payment_method(payment_method_id),
  amount NUMERIC(18,2) NOT NULL,
  currency_id UUID NOT NULL REFERENCES currency(currency_id),
  status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  external_reference VARCHAR(180),
  provider_transaction_id VARCHAR(180),
  paid_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(payment_method_id,provider_transaction_id)
);

CREATE TABLE receivable (
  receivable_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  order_id UUID NOT NULL REFERENCES sales_order(order_id),
  due_date DATE NOT NULL,
  amount NUMERIC(18,2) NOT NULL,
  paid_amount NUMERIC(18,2) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDING'
);

CREATE TABLE invoice_document (
  document_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  order_id UUID NOT NULL REFERENCES sales_order(order_id),
  document_type VARCHAR(30) NOT NULL,
  series VARCHAR(20),
  number VARCHAR(30),
  status VARCHAR(30) NOT NULL DEFAULT 'ISSUED',
  issue_date DATE NOT NULL DEFAULT CURRENT_DATE,
  pdf_url TEXT,
  UNIQUE(tenant_id,document_type,series,number)
);

-- =========================
-- BILLETERA
-- =========================
CREATE TABLE wallet (
  wallet_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  currency_id UUID NOT NULL REFERENCES currency(currency_id),
  balance NUMERIC(18,2) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  UNIQUE(user_id,currency_id)
);

CREATE TABLE wallet_transaction (
  wallet_transaction_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  wallet_id UUID NOT NULL REFERENCES wallet(wallet_id) ON DELETE CASCADE,
  type VARCHAR(30) NOT NULL,
  amount NUMERIC(18,2) NOT NULL,
  balance_after NUMERIC(18,2) NOT NULL,
  reference_type VARCHAR(50),
  reference_id UUID,
  description VARCHAR(255),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =========================
-- SOPORTE
-- =========================
CREATE TABLE support_ticket (
  ticket_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  requester_id UUID NOT NULL REFERENCES app_user(user_id),
  assignee_id UUID REFERENCES app_user(user_id),
  subject VARCHAR(250) NOT NULL,
  category VARCHAR(80),
  priority VARCHAR(30) NOT NULL DEFAULT 'NORMAL',
  status VARCHAR(30) NOT NULL DEFAULT 'OPEN',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  closed_at TIMESTAMPTZ
);

CREATE TABLE ticket_message (
  ticket_message_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  ticket_id UUID NOT NULL REFERENCES support_ticket(ticket_id) ON DELETE CASCADE,
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  body TEXT NOT NULL,
  attachment_url TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =========================
-- CHAT IA
-- =========================
CREATE TABLE ai_conversation (
  conversation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  user_id UUID NOT NULL REFERENCES app_user(user_id),
  context_type VARCHAR(50),
  context_id UUID,
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE ai_message (
  ai_message_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  conversation_id UUID NOT NULL REFERENCES ai_conversation(conversation_id) ON DELETE CASCADE,
  role VARCHAR(30) NOT NULL,
  content TEXT NOT NULL,
  model VARCHAR(100),
  tokens_input INTEGER,
  tokens_output INTEGER,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =========================
-- CMS
-- =========================
CREATE TABLE cms_page (
  page_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  slug VARCHAR(220) NOT NULL,
  title VARCHAR(200) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  published_at TIMESTAMPTZ,
  created_by UUID REFERENCES app_user(user_id),
  updated_by UUID REFERENCES app_user(user_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id,slug)
);

CREATE TABLE cms_revision (
  revision_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  page_id UUID NOT NULL REFERENCES cms_page(page_id) ON DELETE CASCADE,
  version_no INTEGER NOT NULL,
  content_json JSONB NOT NULL,
  created_by UUID REFERENCES app_user(user_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(page_id,version_no)
);

-- =========================
-- LIBRO DE RECLAMACIONES
-- =========================
CREATE TABLE complaint (
  complaint_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  user_id UUID REFERENCES app_user(user_id),
  complaint_code VARCHAR(60) NOT NULL,
  type VARCHAR(30) NOT NULL,
  consumer_name VARCHAR(200) NOT NULL,
  consumer_document VARCHAR(50),
  consumer_email VARCHAR(180),
  consumer_phone VARCHAR(40),
  description TEXT NOT NULL,
  request TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'RECEIVED',
  response TEXT,
  responded_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(tenant_id,complaint_code)
);

-- =========================
-- AUDITORÍA
-- =========================
CREATE TABLE audit_event (
  audit_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id UUID NOT NULL REFERENCES tenant(tenant_id),
  user_id UUID REFERENCES app_user(user_id),
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(100) NOT NULL,
  entity_id UUID,
  before_json JSONB,
  after_json JSONB,
  ip_address INET,
  user_agent TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =========================
-- ÍNDICES PRINCIPALES
-- =========================
CREATE INDEX idx_user_tenant_status ON app_user(tenant_id,status);
CREATE INDEX idx_course_tenant_status ON course(tenant_id,status);
CREATE INDEX idx_course_section_course ON course_section(course_id,position);
CREATE INDEX idx_resource_section ON course_resource(section_id,position);
CREATE INDEX idx_activity_section ON activity(section_id,position);
CREATE INDEX idx_enrollment_user ON enrollment(user_id,status);
CREATE INDEX idx_enrollment_course ON enrollment(course_id,status);
CREATE INDEX idx_order_user ON sales_order(user_id,created_at DESC);
CREATE INDEX idx_order_company ON sales_order(company_id,created_at DESC);
CREATE INDEX idx_payment_order ON payment(order_id);
CREATE INDEX idx_payment_status ON payment(status);
CREATE INDEX idx_ticket_status ON support_ticket(tenant_id,status,priority);
CREATE INDEX idx_ticket_requester ON support_ticket(requester_id);
CREATE INDEX idx_audit_entity ON audit_event(tenant_id,entity,entity_id);
CREATE INDEX idx_audit_created ON audit_event(tenant_id,created_at DESC);
CREATE INDEX idx_ai_conversation_user ON ai_conversation(user_id,created_at DESC);
CREATE INDEX idx_cms_page_status ON cms_page(tenant_id,status);
CREATE INDEX idx_complaint_status ON complaint(tenant_id,status,created_at DESC);
