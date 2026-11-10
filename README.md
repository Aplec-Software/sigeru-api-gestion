# SiGeRU - API de Gestión

Parte de **SiGeRU** (Sistema de Gestión de Residuos Urbanos), proyecto de egreso de Aplec Software (ESI, 2026).

Elementos fijos del sistema: zonas, tipos de residuo, contenedores, centros de acopio y clasificación, maquinaria e inventario de repuestos. También incidencias (reportes de vecinos y por código QR), el mapa público, las estadísticas y los informes.

API REST en PHP 8 con patrón MVC, sin frameworks. Responde siempre en JSON.

## Estructura

```
app/
  Core/          núcleo: router, peticiones y respuestas, validación, base de datos, JWT, idioma, log
  Controllers/   un controlador por recurso
  Models/        acceso a datos (consultas preparadas con PDO)
  Views/         JsonView: arma la respuesta y oculta campos internos
  rutas.php      todas las rutas con los roles que pueden usarlas
lang/            mensajes en español e inglés
public/          index.php (punto de entrada) y .htaccess
bin/             scripts de consola
tests/           pruebas unitarias y de funcionalidad (PHPUnit)
```

## Formato de las respuestas

```json
{"status": "ok", "datos": [ ], "meta": {"total": 48, "pagina": 1, "por_pagina": 15, "paginas": 4}}
{"status": "error", "mensaje": "Hay datos inválidos en la solicitud.", "errores": {"email": "..."}}
```

Códigos usados: 200, 201, 401 (sin sesión), 403 (sin permiso), 404, 405, 409 (conflicto con una regla de negocio),
422 (datos inválidos), 429 (demasiados intentos) y 500.

## Autenticación

El token JWT (RS256) llega en la cookie HttpOnly `sigeru_token` (navegador) o en `Authorization: Bearer <token>`
(Postman, pruebas). Si viene en la cookie, las operaciones que modifican datos deben traer la cabecera
`X-Requested-With: SiGeRU` (protección CSRF). Además de la firma se controla que la sesión no haya sido revocada.

## Variables de entorno

| Variable | Para qué sirve | Ejemplo |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME` | Servidor MySQL | `192.168.56.20`, `3306`, `sigeru` |
| `DB_USER`, `DB_PASS` | Usuario de MySQL propio de este servicio (permisos mínimos) | `api_gestion` |
| `JWT_CLAVE_PUBLICA` | Clave pública para verificar los tokens | `/claves/jwt_publica.pem` |
| `APP_SAL` | Valor secreto para guardar solo un resumen (hash) de la IP de los reportes anónimos | (al azar) |
| `APP_IDIOMA` | Idioma por defecto de los mensajes (`es` o `en`) | `es` |
| `CONFIAR_PROXY` | `1` si está detrás del gateway (toma la IP de `X-Forwarded-For`) | `1` |
| `LOG_ARCHIVO` | Archivo de log; `{fecha}` genera uno por día | `/var/log/sigeru/api-gestion-{fecha}.log` |
| `BASE_PATH` | Prefijo de la ruta si el gateway no lo quita (en Docker queda vacío) | |
| `DB_DRIVER=sqlite`, `DB_RUTA` | Solo desarrollo: usar un archivo SQLite en lugar de MySQL | |

## Pruebas

Las pruebas crean una base SQLite en memoria con la misma estructura y los datos de prueba, así que no hace falta MySQL:

```bash
composer install
composer test
```


## Rutas

Todas se acceden a través del gateway con el prefijo `/api/gestion` (por ejemplo `https://192.168.56.10/api/gestion/salud`).

| Método | Ruta | Controlador | Acceso |
|---|---|---|---|
| GET | `/salud` | Salud::index | pública |
| GET | `/zonas` | Zona::index | pública |
| POST | `/zonas` | Zona::store | administrador |
| GET | `/zonas/{id}` | Zona::show | pública |
| PUT | `/zonas/{id}` | Zona::update | administrador |
| DELETE | `/zonas/{id}` | Zona::destroy | administrador |
| GET | `/tipos-residuo` | TipoResiduo::index | pública |
| POST | `/tipos-residuo` | TipoResiduo::store | administrador |
| GET | `/tipos-residuo/{id}` | TipoResiduo::show | pública |
| PUT | `/tipos-residuo/{id}` | TipoResiduo::update | administrador |
| DELETE | `/tipos-residuo/{id}` | TipoResiduo::destroy | administrador |
| GET | `/contenedores` | Contenedor::index | pública |
| POST | `/contenedores` | Contenedor::store | administrador |
| GET | `/contenedores/codigo/{codigo:[A-Za-z0-9-]+}` | Contenedor::porCodigo | pública |
| GET | `/contenedores/{id}` | Contenedor::show | pública |
| PUT | `/contenedores/{id}` | Contenedor::update | administrador |
| DELETE | `/contenedores/{id}` | Contenedor::destroy | administrador |
| GET | `/contenedores/{id}/historial` | Contenedor::historial | administrador, cuadrilla, operario |
| GET | `/centros` | Centro::index | pública |
| POST | `/centros` | Centro::store | administrador |
| GET | `/centros/{id}` | Centro::show | pública |
| PUT | `/centros/{id}` | Centro::update | administrador |
| DELETE | `/centros/{id}` | Centro::destroy | administrador |
| GET | `/centros/{id}/movimientos` | Centro::movimientos | administrador, operario |
| POST | `/centros/{id}/movimientos` | Centro::registrarMovimiento | administrador, operario |
| GET | `/maquinaria` | Maquinaria::index | administrador, operario |
| POST | `/maquinaria` | Maquinaria::store | administrador |
| GET | `/maquinaria/{id}` | Maquinaria::show | administrador, operario |
| PUT | `/maquinaria/{id}` | Maquinaria::update | administrador |
| DELETE | `/maquinaria/{id}` | Maquinaria::destroy | administrador |
| PUT | `/maquinaria/{id}/estado` | Maquinaria::estado | administrador, operario |
| GET | `/repuestos` | Repuesto::index | administrador, cuadrilla |
| POST | `/repuestos` | Repuesto::store | administrador |
| GET | `/repuestos/{id}` | Repuesto::show | administrador, cuadrilla |
| PUT | `/repuestos/{id}` | Repuesto::update | administrador |
| DELETE | `/repuestos/{id}` | Repuesto::destroy | administrador |
| GET | `/repuestos/{id}/movimientos` | Repuesto::movimientos | administrador |
| POST | `/repuestos/{id}/movimientos` | Repuesto::registrarMovimiento | administrador |
| GET | `/incidencias` | Incidencia::index | administrador, cuadrilla, vecino |
| POST | `/incidencias` | Incidencia::store | administrador, cuadrilla, vecino |
| POST | `/incidencias/qr` | Incidencia::reportarQr | pública |
| GET | `/incidencias/{id}` | Incidencia::show | administrador, cuadrilla, vecino |
| PUT | `/incidencias/{id}` | Incidencia::update | administrador |
| DELETE | `/incidencias/{id}` | Incidencia::destroy | administrador |
| PUT | `/incidencias/{id}/asignar` | Incidencia::asignar | administrador |
| PUT | `/incidencias/{id}/estado` | Incidencia::estado | administrador, cuadrilla |
| GET | `/incidencias/{id}/historial` | Incidencia::historial | administrador, cuadrilla, vecino |
| GET | `/mapa` | Mapa::index | pública |
| GET | `/estadisticas/resumen` | Estadistica::resumen | administrador |
| GET | `/estadisticas/top-desbordados` | Estadistica::topDesbordados | administrador |
| GET | `/estadisticas/zonas-criticas` | Estadistica::zonasCriticas | administrador |
| GET | `/estadisticas/tiempos-resolucion` | Estadistica::tiemposResolucion | administrador |
| GET | `/estadisticas/incidencias-por-mes` | Estadistica::incidenciasPorMes | administrador |
| GET | `/estadisticas/ocupacion-centros` | Estadistica::ocupacionCentros | administrador |
| GET | `/estadisticas/proyeccion-llenado` | Estadistica::proyeccionLlenado | administrador |
| GET | `/informes/incidencias` | Informe::incidencias | administrador |
| GET | `/informes/contenedores` | Informe::contenedores | administrador |

## Docker

La imagen fija la versión de PHP con `ARG PHP_VERSION=8.3.36` (`FROM php:${PHP_VERSION}-apache`), la primera que corrige
las vulnerabilidades «php2shell» (CVE-2026-109618 y CVE-2026-109619, octubre de 2026). El `php.ini` de producción incluye
`session.upload_progress.enabled = 0`, la mitigación que recomendó PHP para CVE-2026-115560 hasta su parche. Para
actualizar a una versión nueva de PHP: cambiar `PHP_VERSION`, reconstruir con `docker compose build --pull` y subir
`PHP_MINIMO` en el servidor (ver `sigeru-infra`).


Normalmente se levanta junto con el resto desde el repositorio `sigeru-infra` (`docker compose up -d --build`).
Para construir solo esta imagen: `docker build -t aplec/sigeru-api-gestion .`
