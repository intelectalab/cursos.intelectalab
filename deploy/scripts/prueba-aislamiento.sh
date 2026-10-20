#!/usr/bin/env bash
# Pruebas de aislamiento entre academias contra el servidor: una academia «seguridad», registrada por la
# página pública con el MISMO correo que el administrador de «demo», intenta entrar a la demo de todas las
# formas conocidas (44 pruebas). Se ejecuta en el servidor, como root, después de cada publicación:
#   bash /var/www/erp/deploy/scripts/prueba-aislamiento.sh
# Las contraseñas se leen de /root/.erp-demo-password y /root/.erp-seguridad-password y nunca se imprimen.
set -u
BASE="https://cursos.intelectalab.com/api/v1"
RESOLVE="--resolve cursos.intelectalab.com:443:127.0.0.1 --resolve demo.cursos.intelectalab.com:443:127.0.0.1"
DEMO_PASS="$(cat /root/.erp-demo-password)"
[ -f /root/.erp-seguridad-password ] || (umask 077; openssl rand -base64 24 | tr -d "/+=\n" | cut -c1-18 > /root/.erp-seguridad-password)
ATK_PASS="$(cat /root/.erp-seguridad-password)"
PASS=0; FAIL=0

json() { php8.4 -r '$d=json_decode(stream_get_contents(STDIN)); $p=explode(".", $argv[1]); foreach($p as $k){ if(is_object($d)&&isset($d->$k)) $d=$d->$k; elseif(is_array($d)&&isset($d[(int)$k])) $d=$d[(int)$k]; else { echo ""; exit; } } echo is_scalar($d)?$d:json_encode($d);' "$1"; }

# check «nombre» «códigos esperados (separados por |)» curl-args…
check() {
  local name="$1" expect="$2"; shift 2
  local code
  rm -f /tmp/aisl.out
  code=$(curl -s -o /tmp/aisl.out -w "%{http_code}" $RESOLVE -H "Accept: application/json" "$@")
  if [[ "|$expect|" == *"|$code|"* ]]; then PASS=$((PASS+1)); printf "OK    %-3s %s\n" "$code" "$name"; else FAIL=$((FAIL+1)); printf "FALLA %-3s %s (se esperaba %s) %s\n" "$code" "$name" "$expect" "$(head -c 160 /tmp/aisl.out)"; fi
}

login() { # academia correo clave
  printf '{"email":"%s","password":"%s"}' "$2" "$3" > /root/.aisl.json
  curl -s $RESOLVE -H "Accept: application/json" -H "Content-Type: application/json" -H "X-Tenant: $1" --data-binary @/root/.aisl.json "$BASE/auth/login" | json data.token
  rm -f /root/.aisl.json
}

echo "== Preparación"
# La academia atacante se registra por la página pública, con el MISMO correo que el administrador de la demo
printf '{"organization_name":"Academia Seguridad","subdomain":"seguridad","first_name":"Ana","last_name":"Atacante","email":"admin@plataforma.com","password":"%s","password_confirmation":"%s","accept_terms":true}' "$ATK_PASS" "$ATK_PASS" > /root/.aisl.json
code=$(curl -s -o /tmp/aisl.out -w "%{http_code}" $RESOLVE -H "Accept: application/json" -H "Content-Type: application/json" --data-binary @/root/.aisl.json "$BASE/tenants")
rm -f /root/.aisl.json
echo "registro de «seguridad»: $code $(head -c 120 /tmp/aisl.out | grep -o '"code":"[A-Z_]*"')"

ATK=$(login seguridad admin@plataforma.com "$ATK_PASS")
DEMO=$(login demo admin@plataforma.com "$DEMO_PASS")
[ -n "$ATK" ] && echo "sesión de la atacante: sí" || echo "sesión de la atacante: NO"
[ -n "$DEMO" ] && echo "sesión del administrador de la demo: sí" || echo "sesión de la demo: NO"

# Identificadores reales de la demo (los que un atacante intentaría adivinar)
DEMO_USER=$(curl -s $RESOLVE -H "Accept: application/json" -H "X-Tenant: demo" -H "Authorization: Bearer $DEMO" "$BASE/users?search=alumno" | json data.0.id)
DEMO_COMPANY=$(curl -s $RESOLVE -H "Accept: application/json" -H "X-Tenant: demo" -H "Authorization: Bearer $DEMO" "$BASE/crm/companies" | json data.0.id)
DEMO_ROLE=$(curl -s $RESOLVE -H "Accept: application/json" -H "X-Tenant: demo" -H "Authorization: Bearer $DEMO" "$BASE/roles" | json data.0.id)
DEMO_TENANT=$(sudo -u postgres psql -tA -d erp_saas_db -c "select id from erp.tenants where slug='demo'")
echo "ids de la demo: usuario=${DEMO_USER:0:8}… empresa=${DEMO_COMPANY:0:8}… rol=${DEMO_ROLE:0:8}…"

echo
echo "== 1. Entrar a la demo con la cuenta de otra academia"
printf '{"email":"admin@plataforma.com","password":"%s"}' "$ATK_PASS" > /root/.aisl.json
check "Mismo correo, clave de la otra academia, en la demo" "401" -X POST -H "Content-Type: application/json" -H "X-Tenant: demo" --data-binary @/root/.aisl.json "$BASE/auth/login"
check "La misma cuenta por el subdominio de la demo (sin validar el certificado)" "401" -k -X POST -H "Content-Type: application/json" --data-binary @/root/.aisl.json "https://demo.cursos.intelectalab.com/api/v1/auth/login"
rm -f /root/.aisl.json

echo
echo "== 2. Usar la sesión de la otra academia apuntando a la demo"
for path in /auth/me /users /students /courses /orders /settings /roles /audit-events /crm/companies /reports/overview /dashboard/academic /dashboard/sales /dashboard/people /dashboard/support /dashboard/export /alerts/summary; do
  check "Sesión ajena + X-Tenant: demo → $path" "401|403" -H "X-Tenant: demo" -H "Authorization: Bearer $ATK" "$BASE$path"
done
check "Sesión ajena + X-Tenant: DEMO (mayúsculas)" "401|403" -H "X-Tenant: DEMO" -H "Authorization: Bearer $ATK" "$BASE/users"
check "Sesión ajena + ?tenant=demo (sin encabezado)" "401|403" -H "Authorization: Bearer $ATK" "$BASE/users?tenant=demo"
check "Sesión ajena por el subdominio demo.cursos… (sin validar el certificado)" "401|403" -k -H "Authorization: Bearer $ATK" "https://demo.cursos.intelectalab.com/api/v1/users"
check "Sesión de la demo + X-Tenant: seguridad (al revés)" "401|403" -H "X-Tenant: seguridad" -H "Authorization: Bearer $DEMO" "$BASE/users"

echo
echo "== 3. Desde su propia academia, pedir datos de la demo por su identificador"
H=(-H "X-Tenant: seguridad" -H "Authorization: Bearer $ATK")
check "Ver la ficha de docente de un usuario de la demo" "404" "${H[@]}" "$BASE/teachers/$DEMO_USER"
check "Editar un usuario de la demo" "404|422" -X PUT -H "Content-Type: application/json" "${H[@]}" --data '{"first_name":"X","last_name":"Y","status":"INACTIVE"}' "$BASE/users/$DEMO_USER"
check "Restablecer la contraseña de un usuario de la demo" "404|422" -X PUT -H "Content-Type: application/json" "${H[@]}" --data '{"password":"Hackeado2026"}' "$BASE/users/$DEMO_USER/password"
check "Cerrar las sesiones de un usuario de la demo" "404" -X DELETE "${H[@]}" "$BASE/users/$DEMO_USER/sessions"
check "Darle roles a un usuario de la demo" "404|422" -X PUT -H "Content-Type: application/json" "${H[@]}" --data "{\"roles\":[\"$DEMO_ROLE\"]}" "$BASE/users/$DEMO_USER/roles"
check "Ver la ficha de alumno de la demo" "404" "${H[@]}" "$BASE/students/$DEMO_USER"
check "Ver una empresa de la demo" "404" "${H[@]}" "$BASE/crm/companies/$DEMO_COMPANY"
check "Editar un rol de la demo" "404" -X PUT -H "Content-Type: application/json" "${H[@]}" --data "{\"name\":\"Hackeado\",\"permissions\":[]}" "$BASE/roles/$DEMO_ROLE"
check "Ponerse un rol de la demo" "422|404" -X PUT -H "Content-Type: application/json" "${H[@]}" --data "{\"roles\":[\"$DEMO_ROLE\"]}" "$BASE/users/$(curl -s $RESOLVE -H "Accept: application/json" "${H[@]}" "$BASE/auth/me" | json data.user.id)/roles"

ATK_USERS=$(curl -s $RESOLVE -H "Accept: application/json" "${H[@]}" "$BASE/users" | json meta.total)
ATK_PANEL=$(curl -s $RESOLVE -H "Accept: application/json" "${H[@]}" "$BASE/dashboard/people" | php8.4 -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["data"]["cards"]??[] as $c){ if($c["label"]==="Usuarios activos"){ echo $c["value"]; } }')
if [ -n "$ATK_PANEL" ] && [ "$ATK_PANEL" -le "${ATK_USERS:-0}" ]; then PASS=$((PASS+1)); echo "OK        El Panel de la atacante cuenta solo sus usuarios ($ATK_PANEL de $ATK_USERS)"; else FAIL=$((FAIL+1)); echo "FALLA     El Panel de la atacante cuenta $ATK_PANEL usuarios y ella tiene $ATK_USERS"; fi

echo
echo "== 4. Colar datos en la demo"
check "Crear un usuario con tenant_id de la demo en el cuerpo" "201|422" -X POST -H "Content-Type: application/json" "${H[@]}" --data "{\"first_name\":\"Intruso\",\"last_name\":\"Prueba\",\"email\":\"intruso@seguridad.test\",\"password\":\"Intruso2026\",\"tenant_id\":\"$DEMO_TENANT\",\"roles\":[\"$(curl -s $RESOLVE -H "Accept: application/json" "${H[@]}" "$BASE/roles" | json data.0.id)\"]}" "$BASE/users"
LEAK=$(curl -s $RESOLVE -H "Accept: application/json" -H "X-Tenant: demo" -H "Authorization: Bearer $DEMO" "$BASE/users?search=intruso" | json meta.total)
if [ "${LEAK:-0}" = "0" ]; then PASS=$((PASS+1)); echo "OK        El usuario creado NO aparece en la demo"; else FAIL=$((FAIL+1)); echo "FALLA     El usuario creado aparece en la demo ($LEAK)"; fi
SEEN=$(curl -s $RESOLVE -H "Accept: application/json" "${H[@]}" "$BASE/users" | grep -o '"email":"[^"]*"' | sort -u | tr '\n' ' ')
echo "          La atacante ve en su lista: $SEEN"

echo
echo "== 5. Sin sesión, y la consola"
check "Sin sesión: usuarios de la demo" "401" -H "X-Tenant: demo" "$BASE/users"
check "Sin sesión: alumnos de la demo" "401" -H "X-Tenant: demo" "$BASE/students"
check "Sin sesión: Panel de la demo" "401" -H "X-Tenant: demo" "$BASE/dashboard/sales"
check "Sin sesión: Excel del Panel de la demo" "401" -H "X-Tenant: demo" "$BASE/dashboard/export"
check "Sesión de academia en la consola de plataforma" "401|403" -H "Authorization: Bearer $ATK" "$BASE/platform/tenants"
check "Sesión de academia pidiendo acceso de soporte a la demo" "401|403|404" -X POST -H "Authorization: Bearer $ATK" "$BASE/platform/tenants/$DEMO_TENANT/support-session"
check "Academia inventada en el encabezado" "404" -H "X-Tenant: no-existe-zz" "$BASE/tenant"
check "Encabezado con caracteres raros (se trata como dominio central)" "200" -H "X-Tenant: ../demo" "$BASE/tenant"
check "Recuperar contraseña en la demo de un correo que no existe (misma respuesta)" "200" -X POST -H "Content-Type: application/json" -H "X-Tenant: demo" --data '{"email":"nadie@seguridad.test"}' "$BASE/auth/forgot-password"

echo
echo "== 6. La base de datos sin academia elegida"
ROWS=$(PGPASSWORD="$(cat /root/.erp-db-password)" psql -h 127.0.0.1 -U erp_app -d erp_saas_db -tA -c "select count(*) from erp.users" 2>&1)
if [ "$ROWS" = "0" ]; then PASS=$((PASS+1)); echo "OK        El usuario de la aplicación sin academia elegida ve 0 usuarios"; else FAIL=$((FAIL+1)); echo "FALLA     El usuario de la aplicación ve $ROWS usuarios sin academia elegida"; fi

echo
echo "Resultado: $PASS pasaron, $FAIL fallaron"
rm -f /tmp/aisl.out
