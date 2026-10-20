#!/usr/bin/env python3
"""
Datos de prueba para que el equipo de testeo recorra la academia demo: cursos con
contenido y actividades, alumnos, ventas (pagadas, en cuotas, pendientes, canceladas,
con cupón y por paquete), notas, un certificado, consultas de soporte, reclamos y CRM.

Todo entra por la API pública del sistema, como lo haría una persona, así que cada
dato pasa por las mismas reglas. Si la academia ya tiene el curso «Excel para
finanzas», no hace nada (no duplica).

En el servidor, como root:
    python3 /var/www/erp/deploy/scripts/datos-de-prueba.py

Variables (opcionales):
    ERP_BASE          https://cursos.intelectalab.com
    ERP_TENANT        demo
    ERP_PASSWORD_FILE /root/.erp-demo-password       clave de las cuentas de la academia (también la de las nuevas)
    ERP_PLATFORM_FILE /root/.erp-platform-admin      «correo: …» y «clave: …» del administrador de plataforma
    ERP_ADMIN         admin@plataforma.com
Las claves se leen de archivos y nunca se imprimen.
"""
import datetime
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("ERP_BASE", "https://cursos.intelectalab.com").rstrip("/") + "/api/v1"
TENANT = os.environ.get("ERP_TENANT", "demo")
ADMIN = os.environ.get("ERP_ADMIN", "admin@plataforma.com")
PASSWORD = open(os.environ.get("ERP_PASSWORD_FILE", "/root/.erp-demo-password"), encoding="utf-8").read().strip()
PLATFORM_FILE = os.environ.get("ERP_PLATFORM_FILE", "/root/.erp-platform-admin")
TODAY = datetime.date.today()


class ApiError(Exception):
    pass


def call(method, path, body=None, token=None, tenant=True, ok=(200, 201)):
    headers = {"Accept": "application/json", "Content-Type": "application/json"}
    if tenant:
        headers["X-Tenant"] = TENANT
    if token:
        headers["Authorization"] = "Bearer " + token
    data = json.dumps(body).encode("utf-8") if body is not None else None
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            status, raw = response.status, response.read()
    except urllib.error.HTTPError as error:
        status, raw = error.code, error.read()
    payload = json.loads(raw) if raw else {}
    if status not in ok:
        raise ApiError(f"{method} {path} → {status}: {json.dumps(payload, ensure_ascii=False)[:400]}")
    return payload


def login(email, password=PASSWORD):
    return call("POST", "/auth/login", {"email": email, "password": password})["data"]["token"]


def step(text):
    print(f"· {text}", flush=True)


STUDENTS = [
    ("Alumno", "De Prueba", "alumno@plataforma.com", None),
    ("Ana", "Torres", "ana.torres@demo.test", "70112233"),
    ("Luis", "Ramírez", "luis.ramirez@demo.test", "70223344"),
    ("María", "Flores", "maria.flores@demo.test", "70334455"),
    ("Jorge", "Castillo", "jorge.castillo@demo.test", "70445566"),
    ("Lucía", "Vargas", "lucia.vargas@demo.test", "70556677"),
    ("Diego", "Huamán", "diego.huaman@demo.test", "70667788"),
    ("Sofía", "Chávez", "sofia.chavez@demo.test", "70778899"),
    ("Pedro", "Salazar", "pedro.salazar@demo.test", "70889900"),
    ("Valeria", "Rojas", "valeria.rojas@demo.test", "70990011"),
]

COURSES = {"excel": "Excel para finanzas", "powerbi": "Power BI desde cero", "marketing": "Marketing digital para emprendedores", "soft": "Comunicación efectiva en el trabajo"}


def key(email):
    return email.split("@")[0].split(".")[0]


def main():
    admin = login(ADMIN)
    if any(c["title"] == COURSES["excel"] for c in call("GET", "/courses?search=Excel%20para%20finanzas", token=admin)["data"]):
        step("Cursos, personas y ventas ya estaban: se completa lo que falte")
    else:
        setup(admin)
    rest(admin, load(admin))
    print("\nListo. Cuentas nuevas con la misma clave de la demo: carlos.mendoza@demo.test (docente) y los alumnos @demo.test.")


def load(admin):
    """Ids de lo ya cargado, para retomar una corrida interrumpida."""
    students = {}
    for _, _, email, _ in STUDENTS:
        found = call("GET", f"/users?search={urllib.parse.quote(email)}", token=admin)["data"]
        students[key(email)] = {"id": next(u["id"] for u in found if u["email"].lower() == email.lower()), "email": email}
    correct = {}
    for bank in call("GET", "/question-banks", token=admin)["data"]:
        for question in call("GET", f"/question-banks/{bank['id']}/questions", token=admin)["data"]:
            correct[question["id"]] = next((o["id"] for o in question["config"].get("options", []) if o.get("correct")), None)
    listed = {c["title"]: c["id"] for c in call("GET", "/courses?per_page=100", token=admin)["data"]}
    state = {"students": students}
    for name, title in COURSES.items():
        course_id = listed[title]
        detail = call("GET", f"/courses/{course_id}", token=admin)["data"]
        state[name] = {"id": course_id, "resources": [r["id"] for s in detail["sections"] for r in s["resources"]]}
        for activity in call("GET", f"/courses/{course_id}/activities", token=admin)["data"]:
            if activity["type"] == "QUIZ":
                state[name]["quiz"] = (activity["id"], [(q["question_id"], correct[q["question_id"]]) for q in activity["questions"]])
            elif activity["type"] == "TASK":
                state[name]["task"] = activity["id"]
    return state


def setup(admin):
    # 1. Sin tope de alumnos ni vencimiento: la prueba gratuita (10 alumnos, 14 días) dejaría a los testers en solo lectura
    lines = dict(line.split(":", 1) for line in open(PLATFORM_FILE, encoding="utf-8").read().splitlines() if ":" in line)
    platform = call("POST", "/platform/auth/login", {"email": lines["correo"].strip(), "password": lines["clave"].strip()}, tenant=False)["data"]["token"]
    tenant_id = call("POST", "/auth/login", {"email": ADMIN, "password": PASSWORD})["data"]["tenant"]["id"]
    call("PUT", f"/platform/tenants/{tenant_id}/subscription", {"plan_id": None}, token=platform, tenant=False)
    step("Academia sin plan: sin tope de alumnos ni vencimiento")

    # 2. Personas
    roles = {r["code"]: r["id"] for r in call("GET", "/roles", token=admin)["data"]}

    def person(first, last, email, role, doc=None):
        found = call("GET", f"/users?search={urllib.parse.quote(email)}", token=admin)["data"]
        match = next((u for u in found if u["email"].lower() == email.lower()), None)
        if match:
            return match["id"]
        body = {"first_name": first, "last_name": last, "email": email, "password": PASSWORD, "roles": [roles[role]]}
        if doc:
            body.update({"doc_type": "DNI", "doc_number": doc})
        return call("POST", "/users", body, token=admin)["data"]["id"]

    teacher = person("Rosa", "Quispe", "docente@plataforma.com", "TEACHER")
    teacher2 = person("Carlos", "Mendoza", "carlos.mendoza@demo.test", "TEACHER")
    person("Equipo", "Soporte", "soporte@plataforma.com", "SUPPORT")
    person("Equipo", "Ventas", "ventas@plataforma.com", "SALES")
    students = {}
    for first, last, email, doc in STUDENTS:
        students[key(email)] = {"id": person(first, last, email, "STUDENT", doc), "email": email}
    step(f"{len(students)} alumnos y 2 docentes (clave: la misma de las cuentas de la demo)")

    # 3. Bancos de preguntas
    def bank(name, questions):
        bank_id = call("POST", "/question-banks", {"name": name, "description": "Preguntas de muestra"}, token=admin)["data"]["id"]
        ids = []
        for statement, options, correct in questions:
            config = {"options": [{"id": chr(97 + i), "text": text, "correct": chr(97 + i) == correct} for i, text in enumerate(options)]}
            ids.append((call("POST", f"/question-banks/{bank_id}/questions", {"type": "SINGLE", "statement": statement, "explanation": None, "config": config}, token=admin)["data"]["id"], correct))
        return ids

    excel_q = bank("Excel para finanzas", [
        ("¿Qué función suma un rango de celdas?", ["CONTAR", "SUMA", "PROMEDIO"], "b"),
        ("¿Qué símbolo fija una referencia de celda?", ["$", "#", "&"], "a"),
        ("¿Qué función busca un valor en una tabla?", ["BUSCARV", "SI", "MAX"], "a"),
    ])
    bi_q = bank("Power BI", [
        ("¿Cómo se llama el lenguaje de fórmulas de Power BI?", ["SQL", "DAX", "VBA"], "b"),
        ("¿Dónde se limpian y transforman los datos?", ["Power Query", "Paint", "Word"], "a"),
    ])
    step("2 bancos de preguntas")

    # 4. Cursos
    video = "https://www.youtube.com/watch?v=dQw4w9WgXcQ"

    def course(data, sections, quiz=None, task=None, teachers=(), publish=True):
        course_id = call("POST", "/courses", {"language": "es", **data}, token=admin)["data"]["id"]
        resources, activities, first_section = [], {}, None
        for title, items in sections:
            section_id = call("POST", f"/courses/{course_id}/sections", {"title": title}, token=admin)["data"]["id"]
            first_section = first_section or section_id
            for kind, item_title, content in items:
                body = {"type": kind, "title": item_title, **({"body": content} if kind == "TEXT" else {"url": content})}
                resources.append(call("POST", f"/courses/{course_id}/sections/{section_id}/resources", body, token=admin)["data"]["id"])
        if quiz:
            quiz_id = call("POST", f"/courses/{course_id}/sections/{section_id}/activities", {"type": "QUIZ", "title": quiz[0], "description": "Responde las preguntas del módulo.", "passing_percent": 60, "max_attempts": 3, "time_limit_minutes": 20, "shuffle": False, "weight": 1}, token=admin)["data"]["id"]
            call("PUT", f"/courses/{course_id}/activities/{quiz_id}/questions", {"items": [{"question_id": q, "points": 1} for q, _ in quiz[1]]}, token=admin)
            activities["quiz"] = (quiz_id, quiz[1])
        if task:
            activities["task"] = call("POST", f"/courses/{course_id}/sections/{section_id}/activities", {"type": "TASK", "title": task, "description": "Sube tu desarrollo en texto. Tienes 7 días desde que empiezas el curso.", "allow_text": True, "allow_file": False, "due_days": 7, "accept_late": True, "weight": 1}, token=admin)["data"]["id"]
        if teachers:
            call("PUT", f"/courses/{course_id}/teachers", {"user_ids": list(teachers)}, token=admin)
        if publish:
            call("POST", f"/courses/{course_id}/publish", {"note": "Primera versión"}, token=admin)
        return {"id": course_id, "resources": resources, **activities}

    excel = course(
        {"title": "Excel para finanzas", "summary": "Fórmulas, tablas y gráficos para el trabajo financiero diario.", "description": "Aprende a ordenar, calcular y presentar información financiera con Excel.", "category": "Ofimática", "level": "BASIC", "duration_hours": 12, "access_days": 180, "price": 120, "issues_certificate": True},
        [("Módulo 1 · Primeros pasos", [("TEXT", "Bienvenida", "En este curso trabajarás con casos reales de una pequeña empresa."), ("VIDEO", "La hoja de cálculo", video)]),
         ("Módulo 2 · Fórmulas y funciones", [("TEXT", "Referencias absolutas y relativas", "Usa el símbolo $ para fijar una fila o una columna."), ("TEXT", "BUSCARV paso a paso", "BUSCARV encuentra un valor en la primera columna de una tabla.")])],
        quiz=("Evaluación del módulo 2", excel_q), task="Presupuesto mensual", teachers=[teacher])
    powerbi = course(
        {"title": "Power BI desde cero", "summary": "Tableros e indicadores a partir de tus datos.", "description": "Conecta, limpia y visualiza datos para tomar decisiones.", "category": "Análisis de datos", "level": "INTERMEDIATE", "duration_hours": 16, "access_days": 365, "price": 250, "issues_certificate": True},
        [("Módulo 1 · Conectar datos", [("VIDEO", "Qué es Power BI", video), ("TEXT", "Power Query", "Power Query limpia y transforma los datos antes del análisis.")]),
         ("Módulo 2 · Medidas con DAX", [("TEXT", "Tu primera medida", "Una medida calcula un resultado según los filtros del reporte.")])],
        quiz=("Evaluación final", bi_q), teachers=[teacher, teacher2])
    marketing = course(
        {"title": "Marketing digital para emprendedores", "summary": "Redes sociales, contenido y anuncios con poco presupuesto.", "description": "Arma un plan de marketing digital para tu negocio.", "category": "Marketing", "level": "BASIC", "duration_hours": 8, "price": 89, "issues_certificate": True},
        [("Módulo único", [("TEXT", "Tu cliente ideal", "Define a quién le vendes antes de publicar."), ("VIDEO", "Contenido que vende", video), ("TEXT", "Anuncios", "Empieza con poco presupuesto y mide los resultados.")])],
        teachers=[teacher2])
    soft = course(
        {"title": "Comunicación efectiva en el trabajo", "summary": "Curso gratuito de habilidades blandas.", "description": "Escucha activa, mensajes claros y reuniones productivas.", "category": "Habilidades blandas", "level": "BASIC", "duration_hours": 3, "price": 0, "issues_certificate": False},
        [("Módulo único", [("TEXT", "Escucha activa", "Escuchar para entender, no para responder."), ("TEXT", "Reuniones que sirven", "Toda reunión necesita un objetivo y un responsable.")])])
    course({"title": "Python para análisis de datos", "summary": "En preparación.", "category": "Análisis de datos", "level": "INTERMEDIATE", "price": 300}, [("Módulo 1", [("TEXT", "Instalación", "Instala Python y Jupyter.")])], publish=False)
    step("5 cursos (4 publicados, 1 en borrador) con videos, lecturas, cuestionarios y una tarea")

    # 5. Promoción y paquete
    call("POST", "/promotions", {"name": "Bienvenida", "discount_type": "PERCENT", "discount_value": 15, "starts_at": TODAY.isoformat(), "ends_at": (TODAY + datetime.timedelta(days=90)).isoformat(), "max_redemptions": 100, "once_per_customer": True, "min_purchase": None, "applies_to": "ALL", "code": "BIENVENIDA15", "label": "Campaña de lanzamiento"}, token=admin)
    package = call("POST", "/packages", {"title": "Ruta de análisis de datos", "summary": "Excel y Power BI a precio especial.", "price": 320, "status": "ACTIVE", "course_ids": [excel["id"], powerbi["id"]]}, token=admin)["data"]["id"]
    step("Cupón BIENVENIDA15 (15 %) y paquete «Ruta de análisis de datos»")

    # 6. Ventas
    tokens = {key: login(s["email"]) for key, s in students.items()}

    def order(who, body):
        return call("POST", "/orders", body, token=tokens[who])["data"]

    def pay(order_id, method, reference):
        call("POST", f"/orders/{order_id}/confirm-payment", {"method": method, "reference": reference, "notes": None, "paid_at": TODAY.isoformat()}, token=admin)

    pay(order("ana", {"course_id": excel["id"], "coupon_code": "BIENVENIDA15"})["id"], "YAPE", "YP-100245")
    pay(order("luis", {"course_id": powerbi["id"]})["id"], "TRANSFER", "OP-778120")
    pay(order("lucia", {"package_id": package})["id"], "TRANSFER", "OP-778301")
    cuotas = order("maria", {"course_id": excel["id"]})
    call("PUT", f"/orders/{cuotas['id']}/installments", {"installments": [{"amount": 60, "due_date": TODAY.isoformat()}, {"amount": 60, "due_date": (TODAY + datetime.timedelta(days=30)).isoformat()}]}, token=admin)
    pay(cuotas["id"], "YAPE", "YP-100377")
    order("jorge", {"course_id": marketing["id"]})
    cancelada = order("pedro", {"course_id": powerbi["id"]})
    call("POST", f"/orders/{cancelada['id']}/cancel", token=admin)
    order("diego", {"course_id": soft["id"]})
    order("valeria", {"course_id": soft["id"]})
    call("POST", f"/courses/{marketing['id']}/enrollments", {"student_ids": [students["sofia"]["id"]]}, token=admin)
    call("POST", f"/courses/{excel['id']}/enrollments", {"student_ids": [students["alumno"]["id"]]}, token=admin)
    call("POST", f"/courses/{soft['id']}/enrollments", {"student_ids": [students["alumno"]["id"]]}, token=admin)
    step("Ventas: 3 pagadas (una con cupón, una de paquete), 1 en cuotas, 1 pendiente, 1 cancelada, 2 gratuitas y 3 matrículas manuales")


def rest(admin, state):
    """Avance, soporte, reclamos y CRM. Cada parte se salta si ya está hecha."""
    excel, powerbi, marketing, soft = state["excel"], state["powerbi"], state["marketing"], state["soft"]
    tokens = {name: login(s["email"]) for name, s in state["students"].items()}

    # 7. Avance, notas y certificado
    def complete(who, c, resources):
        for resource in resources:
            call("POST", f"/me/courses/{c['id']}/resources/{resource}/complete", token=tokens[who])

    def quiz(who, c, right=True):
        quiz_id, questions = c["quiz"]
        attempt = call("POST", f"/me/courses/{c['id']}/activities/{quiz_id}/attempts", token=tokens[who])["data"]["id"]
        answers = {q: (correct if right else ("c" if correct != "c" else "a")) for q, correct in questions}
        call("POST", f"/me/attempts/{attempt}/submit", {"answers": answers}, token=tokens[who])

    def task(who, c, text):
        return call("POST", f"/me/courses/{c['id']}/activities/{c['task']}/submission", {"body": text}, token=tokens[who])["data"]["id"]

    if call("GET", "/certificates", token=admin)["data"]:
        step("Avance y certificados: ya estaban")
    else:
        teacher_token = login("docente@plataforma.com")
        complete("ana", excel, excel["resources"])
        quiz("ana", excel)
        submission = task("ana", excel, "Ingresos: 4 500. Gastos fijos: 2 800. Variables: 900. Ahorro: 800 (17,8 %).")
        call("POST", f"/courses/{excel['id']}/submissions/{submission}/grade", {"grade_percent": 90, "feedback": "Muy bien organizado. Revisa el formato de moneda."}, token=teacher_token)
        complete("maria", excel, excel["resources"][:2])
        quiz("maria", excel, right=False)
        complete("alumno", excel, excel["resources"][:3])
        quiz("alumno", excel)
        task("alumno", excel, "Adjunto mi presupuesto: ingresos 3 200, gastos 2 950.")
        complete("alumno", soft, soft["resources"][:1])
        complete("luis", powerbi, powerbi["resources"][:2])
        complete("lucia", powerbi, powerbi["resources"])
        quiz("lucia", powerbi)
        complete("diego", soft, soft["resources"])
        complete("sofia", marketing, marketing["resources"][:1])
        step("Avance: Ana completó Excel (nota 90 y certificado), Lucía completó Power BI (certificado), tareas por calificar y un cuestionario desaprobado")

    # 8. Soporte
    if call("GET", "/tickets", token=admin)["data"]:
        step("Soporte: ya estaba")
    else:
        support = login("soporte@plataforma.com")
        t1 = call("POST", "/tickets", {"subject": "No encuentro mi certificado", "category": "CERTIFICATE", "body": "Terminé el curso de Excel y no sé dónde descargar el certificado."}, token=tokens["ana"])["data"]["id"]
        call("POST", f"/tickets/{t1}/messages", {"body": "Hola Ana, está en «Mis certificados», en el menú de la izquierda.", "is_internal": False}, token=support)
        call("POST", f"/tickets/{t1}/status", {"status": "RESOLVED"}, token=support)
        call("POST", "/tickets", {"subject": "No carga el video del módulo 1", "category": "COURSE", "body": "El video de «Qué es Power BI» se queda cargando."}, token=tokens["luis"])
        call("POST", "/tickets", {"subject": "¿Puedo pagar en cuotas?", "category": "PAYMENT", "body": "Quiero llevar Marketing digital y pagarlo en dos partes."}, token=tokens["jorge"])
        step("3 consultas de soporte (una resuelta, dos esperando respuesta)")

    # 9. Libro de Reclamaciones (público, sin sesión)
    def claim(kind, first, last, doc, detail, request):
        return call("POST", "/claims", {"type": kind, "first_name": first, "last_name": last, "doc_type": "DNI", "doc_number": doc, "address": "Av. Arequipa 1250, Lima", "email": f"{first.lower()}.{last.lower()}@correo-prueba.pe", "phone": "987654321", "is_minor": False, "good_type": "SERVICE", "good_description": "Curso virtual", "amount": 120, "order_reference": None, "detail": detail, "request": request, "accepted": True})["data"]["id"]

    if call("GET", "/claims", token=admin)["data"]:
        step("Libro de Reclamaciones: ya estaba")
    else:
        claim("RECLAMO", "Roberto", "Paredes", "45671234", "Pagué el curso de Excel por transferencia y después de dos días no tengo acceso.", "Que activen mi acceso al curso.")
        answered = claim("QUEJA", "Carmen", "Silva", "45679876", "La atención por WhatsApp demoró más de una semana en responderme.", "Mejorar los tiempos de respuesta.")
        call("POST", f"/claims/{answered}/respond", {"body": "Lamentamos la demora. Ya reforzamos el equipo de atención y respondemos en menos de 24 horas."}, token=admin)
        step("2 hojas del Libro de Reclamaciones (una respondida)")

    # 10. CRM
    if any(c["name"] == "Minera Andes S.A.C." for c in call("GET", "/crm/companies?search=Minera", token=admin)["data"]):
        step("CRM: ya estaba")
    else:
        sales = login("ventas@plataforma.com")
        for lead in [
            {"first_name": "Carla", "last_name": "Díaz", "company_name": "Clínica San Pablo", "position": "Jefa de RR. HH.", "email": "carla.diaz@empresa-prueba.pe", "phone": "999111222", "source": "WEB", "interest": "Capacitación en Excel para 30 personas"},
            {"first_name": "Martín", "last_name": "Linares", "company_name": "Transportes Linares", "position": "Gerente", "email": None, "phone": "999333444", "source": "REFERRAL", "interest": "Power BI para el área comercial"},
        ]:
            call("POST", "/crm/leads", lead, token=sales)
        company = call("POST", "/crm/companies", {"name": "Minera Andes S.A.C.", "tax_id": "20512345678", "industry": "Minería", "email": "contacto@minera-prueba.pe", "phone": "014445566", "address": "Av. Javier Prado 2400", "city": "Lima", "notes": None}, token=sales)["data"]["id"]
        detail = call("POST", f"/crm/companies/{company}/contacts", {"first_name": "Juan", "last_name": "Soto", "position": "Gerente de Capacitación", "email": "juan.soto@minera-prueba.pe", "phone": None, "is_primary": True}, token=sales)["data"]
        opportunity = call("POST", "/crm/opportunities", {"company_id": company, "contact_id": detail["contacts"][0]["id"], "title": "Excel y Power BI para 25 analistas", "estimated_amount": 7500, "expected_close_date": (TODAY + datetime.timedelta(days=45)).isoformat(), "notes": None}, token=sales)["data"]["id"]
        for stage in ["CONTACTED", "PROPOSAL"]:
            call("POST", f"/crm/opportunities/{opportunity}/stage", {"stage": stage}, token=sales)
        second = call("POST", "/crm/companies", {"name": "Textiles del Sur E.I.R.L.", "tax_id": "20698765432", "industry": "Textil", "email": None, "phone": None, "address": None, "city": "Arequipa", "notes": None}, token=sales)["data"]["id"]
        call("POST", "/crm/opportunities", {"company_id": second, "contact_id": None, "title": "Marketing digital para el equipo de ventas", "estimated_amount": 2400, "expected_close_date": (TODAY + datetime.timedelta(days=60)).isoformat(), "notes": None}, token=sales)
        step("CRM: 2 prospectos, 2 empresas y 2 oportunidades")



if __name__ == "__main__":
    try:
        main()
    except ApiError as error:
        print(f"\nSe detuvo: {error}", file=sys.stderr)
        sys.exit(1)
