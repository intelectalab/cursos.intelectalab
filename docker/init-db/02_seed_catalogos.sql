
SET search_path TO erp, public;

INSERT INTO currency(code,name,symbol,decimals) VALUES
('PEN','Sol peruano','S/',2),
('USD','Dólar estadounidense','$',2)
ON CONFLICT(code) DO NOTHING;

INSERT INTO permission(code,description) VALUES
('dashboard.read','Consultar dashboards'),
('course.read','Consultar cursos'),
('course.create','Crear cursos'),
('course.update','Editar cursos'),
('course.publish','Publicar cursos'),
('enrollment.read','Consultar matrículas'),
('enrollment.create','Crear matrículas'),
('evaluation.read','Consultar evaluaciones'),
('evaluation.create','Crear evaluaciones'),
('evaluation.grade','Calificar evaluaciones'),
('certificate.read','Consultar certificados'),
('certificate.issue','Emitir certificados'),
('order.read','Consultar ventas'),
('order.create','Crear ventas'),
('payment.read','Consultar pagos'),
('ticket.read','Consultar tickets'),
('ticket.create','Crear tickets'),
('ticket.assign','Asignar tickets'),
('ticket.close','Cerrar tickets'),
('cms.read','Consultar CMS'),
('cms.create','Crear páginas'),
('cms.update','Editar páginas'),
('cms.publish','Publicar páginas'),
('complaint.read','Consultar reclamaciones'),
('complaint.create','Registrar reclamaciones'),
('complaint.respond','Responder reclamaciones'),
('report.read','Consultar reportes'),
('audit.read','Consultar auditoría')
ON CONFLICT(code) DO NOTHING;
