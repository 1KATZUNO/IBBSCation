# Plan de Transformacion Multi-Tenant - IBBSC → IBBSaaS

> Documento exhaustivo de cada modificacion necesaria para convertir la app de administracion
> de una sola iglesia en una plataforma multi-iglesia con branding, rubros y configuracion dinamica.

---

## TABLA DE CONTENIDOS

1. [Resumen Ejecutivo](#1-resumen-ejecutivo)
2. [Arquitectura Multi-Tenant](#2-arquitectura-multi-tenant)
3. [Nuevas Tablas de Base de Datos](#3-nuevas-tablas-de-base-de-datos)
4. [Tablas Existentes que Cambian](#4-tablas-existentes-que-cambian)
5. [Sistema de Branding Dinamico (Logo + Colores)](#5-sistema-de-branding-dinamico-logo--colores)
6. [Sistema de Rubros/Categorias Dinamicos](#6-sistema-de-rubroscategorias-dinamicos)
7. [Sistema de Asistencia Dinamica](#7-sistema-de-asistencia-dinamica)
8. [Autenticacion por Dominio de Email](#8-autenticacion-por-dominio-de-email)
9. [Panel de Super Admin](#9-panel-de-super-admin)
10. [Modificaciones por Archivo (Inventario Completo)](#10-modificaciones-por-archivo-inventario-completo)
11. [Migraciones Necesarias](#11-migraciones-necesarias)
12. [Nuevos Archivos a Crear](#12-nuevos-archivos-a-crear)
13. [Orden de Implementacion](#13-orden-de-implementacion)
14. [Riesgos y Decisiones Pendientes](#14-riesgos-y-decisiones-pendientes)

---

## 1. RESUMEN EJECUTIVO

### Estado actual
- App monolito para **una sola iglesia** (IBBSC - Iglesia Biblica Bautista Santa Cruz)
- **459 referencias** a clases `blue-*` en 44 archivos de vistas
- **8 categorias de ingreso** quemadas en schema, servicios, controladores y vistas
- **~50 columnas demograficas** quemadas en tabla `asistencia`
- **176 referencias** al simbolo de moneda `₡` en vistas
- **65+ referencias** al texto "IBBSC" en vistas y controladores
- **23 referencias** a `Logo.png` / `Logo2.png`
- **4 URLs** de redes sociales quemadas
- **5 tipos de culto** en ENUM fijo
- **5 roles** en ENUM fijo (se agrega rol `musico`)

### Objetivo
Plataforma SaaS donde:
- Cada iglesia tiene su propio espacio (tenant) con branding, rubros y configuracion independiente
- Un super admin (`@admin.com`) gestiona todas las iglesias
- La deteccion de tenant es por dominio de email (`@ibbla.com`, `@ibba.com`, etc.)
- Misma base de datos MySQL, aislamiento por columna `tenant_id`

---

## 2. ARQUITECTURA MULTI-TENANT

### Enfoque elegido: Single Database, Shared Schema con `tenant_id`

```
                    ┌─────────────────────┐
                    │   Super Admin Panel  │
                    │   (@admin.com)       │
                    └──────────┬──────────┘
                               │
              ┌────────────────┼────────────────┐
              │                │                 │
     ┌────────▼───────┐ ┌─────▼──────┐ ┌───────▼──────┐
     │  Tenant IBBSC  │ │ Tenant     │ │  Tenant      │
     │  @ibbsc.com    │ │ @ibbla.com │ │  @ibba.com   │
     │  Logo: ✝       │ │ Logo: 🕊   │ │  Logo: 📖    │
     │  Color: Azul   │ │ Color:Rojo │ │  Color:Verde │
     │  Rubros: 8     │ │ Rubros: 5  │ │  Rubros: 12  │
     └────────────────┘ └────────────┘ └──────────────┘
              │                │                 │
              └────────────────┼────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │   MySQL Database     │
                    │   (tenant_id en     │
                    │    cada tabla)       │
                    └─────────────────────┘
```

### Mecanismo de Tenant Resolution
1. Usuario inicia sesion con email `juan@ibbla.com`
2. Middleware extrae dominio: `ibbla.com`
3. Busca en `tenant_email_domains` → encuentra `tenant_id = 2`
4. Setea `tenant_id` en sesion y en un singleton
5. Global Scopes en Eloquent filtran automaticamente todas las queries
6. Si dominio es `admin.com` → redirige a panel Super Admin

---

## 3. NUEVAS TABLAS DE BASE DE DATOS

### 3.1 `tenants` (Iglesias)
```sql
CREATE TABLE tenants (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(255) NOT NULL,          -- "Iglesia Biblica Bautista Santa Cruz"
    siglas          VARCHAR(20) NOT NULL,             -- "IBBSC", "IBBLA", "IBBA" (se muestra en sidebar, titulos, PDFs)
    slug            VARCHAR(100) NOT NULL UNIQUE,    -- "ibbsc" (para URLs internas)
    -- Branding
    logo_path       VARCHAR(500) NULL,               -- "tenants/1/logo.png"
    logo_pdf_path   VARCHAR(500) NULL,               -- "tenants/1/logo-pdf.png" (para DomPDF)
    favicon_path    VARCHAR(500) NULL,               -- "tenants/1/favicon.png"
    -- Colores (paleta completa)
    color_50        VARCHAR(7) DEFAULT '#eff6ff',
    color_100       VARCHAR(7) DEFAULT '#dbeafe',
    color_200       VARCHAR(7) DEFAULT '#bfdbfe',
    color_300       VARCHAR(7) DEFAULT '#93c5fd',
    color_400       VARCHAR(7) DEFAULT '#60a5fa',
    color_500       VARCHAR(7) DEFAULT '#3b82f6',
    color_600       VARCHAR(7) DEFAULT '#2563eb',
    color_700       VARCHAR(7) DEFAULT '#1d4ed8',
    color_800       VARCHAR(7) DEFAULT '#1e40af',
    color_900       VARCHAR(7) DEFAULT '#1e3a8a',
    -- Alternativa: paleta predefinida
    color_theme     VARCHAR(20) DEFAULT 'blue',      -- blue|red|green|purple|orange|teal
    use_custom_colors BOOLEAN DEFAULT FALSE,          -- si true, usa color_50..900; si false, usa color_theme
    -- Configuracion regional
    timezone        VARCHAR(50) DEFAULT 'America/Costa_Rica',
    locale          VARCHAR(10) DEFAULT 'es',
    moneda_codigo   VARCHAR(3) DEFAULT 'CRC',
    moneda_simbolo  VARCHAR(5) DEFAULT '₡',
    -- Info de contacto
    direccion       TEXT NULL,
    telefono        VARCHAR(30) NULL,
    email_contacto  VARCHAR(255) NULL,
    sitio_web       VARCHAR(500) NULL,
    -- Redes sociales (JSON flexible)
    redes_sociales  JSON NULL,                        -- {"instagram":"url","facebook":"url","youtube":"url"}
    -- Estado
    activo          BOOLEAN DEFAULT TRUE,
    max_usuarios    INT DEFAULT 10,                   -- limite por plan
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);
```

### 3.2 `tenant_email_domains` (Dominios de email por tenant)
```sql
CREATE TABLE tenant_email_domains (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    dominio     VARCHAR(255) NOT NULL UNIQUE,  -- "ibbsc.com", "ibbla.com"
    principal   BOOLEAN DEFAULT FALSE,          -- dominio principal del tenant
    activo      BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
```

### 3.3 `tenant_categories` (Rubros dinamicos por iglesia)
```sql
CREATE TABLE tenant_categories (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    nombre              VARCHAR(100) NOT NULL,      -- "Diezmo", "Misiones", etc.
    slug                VARCHAR(100) NOT NULL,       -- "diezmo", "misiones"
    tipo                ENUM('ingreso', 'compromiso', 'ambos') DEFAULT 'ambos',
    -- Comportamiento
    excluir_de_promesas BOOLEAN DEFAULT FALSE,       -- true para "diezmo", "ofrenda_especial"
    es_ofrenda_suelta   BOOLEAN DEFAULT FALSE,       -- si es categoria de ofrenda sin sobre
    -- Presentacion
    icono               VARCHAR(50) NULL,            -- nombre de icono o emoji
    color               VARCHAR(7) NULL,             -- color para graficas
    orden               INT DEFAULT 0,
    activa              BOOLEAN DEFAULT TRUE,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, slug)
);
```

### 3.4 `tenant_service_types` (Tipos de culto por iglesia)
```sql
CREATE TABLE tenant_service_types (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    nombre      VARCHAR(100) NOT NULL,      -- "Domingo AM", "Miercoles", etc.
    slug        VARCHAR(100) NOT NULL,
    orden       INT DEFAULT 0,
    activo      BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, slug)
);
```

### 3.5 `tenant_demographic_groups` (Grupos demograficos de asistencia)
```sql
CREATE TABLE tenant_demographic_groups (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    nombre          VARCHAR(100) NOT NULL,  -- "Capilla", "Clase 0-1", etc.
    slug            VARCHAR(100) NOT NULL,
    tiene_maestros  BOOLEAN DEFAULT FALSE,
    orden           INT DEFAULT 0,
    activo          BOOLEAN DEFAULT TRUE,
    color           VARCHAR(7) NULL,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, slug)
);
```

### 3.6 `tenant_demographic_fields` (Campos dentro de cada grupo)
```sql
CREATE TABLE tenant_demographic_fields (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id    BIGINT UNSIGNED NOT NULL,
    nombre      VARCHAR(100) NOT NULL,      -- "Adultos Hombres", "Jovenes Mujeres"
    slug        VARCHAR(100) NOT NULL,       -- "adultos_hombres"
    es_maestro  BOOLEAN DEFAULT FALSE,       -- si es campo de maestros
    orden       INT DEFAULT 0,
    activo      BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES tenant_demographic_groups(id) ON DELETE CASCADE
);
```

### 3.7 `tenant_roles` (Roles personalizables por iglesia)
```sql
CREATE TABLE tenant_roles (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    nombre      VARCHAR(50) NOT NULL,       -- "admin", "tesorero", etc.
    slug        VARCHAR(50) NOT NULL,
    permisos    JSON NOT NULL,               -- {"recuento":true,"asistencia":true,"admin":false,"mi_perfil":true}
    es_default  BOOLEAN DEFAULT FALSE,       -- rol por defecto al registrarse
    orden       INT DEFAULT 0,
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, slug)
);
```

#### Roles default que se crean automaticamente al crear una iglesia:

| Rol | Slug | Permisos | Notas |
|-----|------|----------|-------|
| **Administrador** | `admin` | `{"recuento":true,"asistencia":true,"admin":true,"mi_perfil":true,"dashboard":true,"reportes":true}` | Acceso total a la iglesia |
| **Tesorero** | `tesorero` | `{"recuento":true,"dashboard":true,"reportes":true,"mi_perfil":true}` | Solo finanzas |
| **Asistente** | `asistente` | `{"asistencia":true,"mi_perfil":true}` | Solo asistencia |
| **Miembro** | `miembro` | `{"mi_perfil":true}` | Solo ve sus propias promesas/contribuciones en `/mi-perfil` |
| **Musico** | `musico` | `{"mi_perfil":true}` | Mismo acceso que miembro: solo ve sus propias promesas/contribuciones en `/mi-perfil`. Existe como rol separado para distinguir musicos en reportes o futuras funcionalidades |
| **Invitado** | `invitado` | `{}` | Solo ve la pagina principal, sin acceso a datos |

**Nota sobre `musico`:** El rol musico tiene exactamente el mismo acceso que `miembro` (solo `/mi-perfil` donde puede ver sus promesas, compromisos y contribuciones). La diferencia es que existe como rol distinguible para que la iglesia pueda identificar a sus musicos en la lista de usuarios, y en el futuro podria tener acceso a funcionalidades especificas (partituras, horarios de ensayo, etc.).

### 3.8 `totales_culto_detalle` (Reemplazo de columnas fijas en totales_culto)
```sql
CREATE TABLE totales_culto_detalle (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    totales_culto_id    BIGINT UNSIGNED NOT NULL,
    category_id         BIGINT UNSIGNED NOT NULL,
    monto               DECIMAL(15,2) DEFAULT 0,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP,
    FOREIGN KEY (totales_culto_id) REFERENCES totales_culto(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES tenant_categories(id),
    UNIQUE(totales_culto_id, category_id)
);
```

### 3.9 `asistencia_detalle` (Reemplazo de ~50 columnas fijas)
```sql
CREATE TABLE asistencia_detalle (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asistencia_id           BIGINT UNSIGNED NOT NULL,
    demographic_field_id    BIGINT UNSIGNED NOT NULL,
    cantidad                INT DEFAULT 0,
    created_at              TIMESTAMP,
    updated_at              TIMESTAMP,
    FOREIGN KEY (asistencia_id) REFERENCES asistencia(id) ON DELETE CASCADE,
    FOREIGN KEY (demographic_field_id) REFERENCES tenant_demographic_fields(id),
    UNIQUE(asistencia_id, demographic_field_id)
);
```

---

## 4. TABLAS EXISTENTES QUE CAMBIAN

### 4.1 `users`
```
AGREGAR:   tenant_id       BIGINT UNSIGNED NULL (NULL = super admin)
AGREGAR:   tenant_role_id  BIGINT UNSIGNED NULL (FK a tenant_roles)
AGREGAR:   is_super_admin  BOOLEAN DEFAULT FALSE
ELIMINAR:  rol             ENUM (reemplazado por tenant_role_id)
```

### 4.2 `personas`
```
AGREGAR:   tenant_id    BIGINT UNSIGNED NOT NULL
AGREGAR:   INDEX(tenant_id)
```

### 4.3 `cultos`
```
AGREGAR:   tenant_id         BIGINT UNSIGNED NOT NULL
MODIFICAR: tipo_culto        VARCHAR → FK a tenant_service_types (o guardar slug)
ELIMINAR:  ENUM de tipo_culto
AGREGAR:   service_type_id   BIGINT UNSIGNED NULL (FK a tenant_service_types)
```

### 4.4 `sobres`
```
Sin cambio directo (hereda tenant_id del culto via relacion)
Opcional: AGREGAR tenant_id para queries directas mas eficientes
```

### 4.5 `sobre_detalles`
```
MODIFICAR: categoria    VARCHAR → category_id BIGINT UNSIGNED (FK a tenant_categories)
```

### 4.6 `totales_culto`
```
ELIMINAR columnas (mover a totales_culto_detalle):
  - total_diezmo
  - total_ofrenda_especial
  - total_misiones
  - total_seminario
  - total_campa
  - total_prestamo
  - total_construccion
  - total_micro

MANTENER:
  - total_suelto
  - total_general
  - cantidad_sobres
  - cantidad_transferencias
```

### 4.7 `asistencia`
```
ELIMINAR todas las columnas demograficas (~50 columnas):
  - chapel_adultos_hombres, chapel_adultos_mujeres, ...
  - clase_0_1_hombres, clase_0_1_mujeres, ...
  - clase_2_6_*, clase_7_8_*, clase_9_11_*
  - salvos_*, bautismos_*, visitas_*

MANTENER:
  - id, culto_id, total_asistencia
  - cerrado, cerrado_at, cerrado_por

(Datos demograficos ahora en asistencia_detalle)
```

### 4.8 `promesas`
```
MODIFICAR: categoria → category_id BIGINT UNSIGNED (FK a tenant_categories)
Opcional:  AGREGAR tenant_id para queries directas
```

### 4.9 `compromisos`
```
MODIFICAR: categoria → category_id BIGINT UNSIGNED (FK a tenant_categories)
MODIFICAR: UNIQUE(persona_id, categoria, año, mes) → UNIQUE(persona_id, category_id, año, mes)
```

### 4.10 `audit_logs`
```
AGREGAR: tenant_id BIGINT UNSIGNED NULL
```

### 4.11 `clases_asistencia`
```
AGREGAR: tenant_id BIGINT UNSIGNED NOT NULL
(Esta tabla ya existia con configuracion, solo falta tenant_id)
```

---

## 5. SISTEMA DE BRANDING DINAMICO (LOGO + COLORES)

### 5.1 Logo Dinamico

#### Archivos que referencian Logo.png (web app):

| Archivo | Linea(s) | Referencia actual | Cambio |
|---------|----------|-------------------|--------|
| `layouts/admin.blade.php` | 16-18 | `asset('images/Logo.png')` x3 (favicon) | `$tenant->favicon_url` |
| `layouts/admin.blade.php` | 37 | `asset('images/Logo.png')` (sidebar) | `$tenant->logo_url` |
| `layouts/admin.blade.php` | 198 | `asset('images/Logo.png')` (logout overlay) | `$tenant->logo_url` |
| `layouts/guest.blade.php` | 11-13 | `asset('images/Logo.png')` x3 (favicon) | `$tenant->favicon_url` |
| `layouts/guest.blade.php` | 33 | `asset('images/Logo.png')` (login header) | `$tenant->logo_url` |
| `layouts/app.blade.php` | 11-13 | `asset('images/Logo.png')` x3 (favicon) | `$tenant->favicon_url` |
| `principal/index.blade.php` | 12 | `asset('images/Logo.png')` (pagina principal) | `$tenant->logo_url` |
| `auth/login.blade.php` | 73 | `asset('images/Logo.png')` (pantalla de carga) | `$tenant->logo_url` |

**Total: 15 referencias a Logo.png**

#### Archivos que referencian Logo2.png (PDFs):

| Archivo | Linea | Referencia actual | Cambio |
|---------|-------|-------------------|--------|
| `pdfs/reporte-personas.blade.php` | 508 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/reporte-general.blade.php` | 266 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/reporte-contribuciones.blade.php` | 199 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/recuento-individual.blade.php` | 47 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/ingresos.blade.php` | 29 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/promesas.blade.php` | 40 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/asistencia.blade.php` | 33 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/asistencia-culto.blade.php` | 28 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/asistencia-mes.blade.php` | 25 | `public_path('images/Logo2.png')` | `$tenant->logoPdfBase64()` |
| `pdfs/promesas-anual.blade.php` | 171 | `public_path('logo-ibbsc.png')` | `$tenant->logoPdfBase64()` |

**Total: 10 referencias a Logo2.png**

#### Implementacion del logo:
```php
// Modelo Tenant
public function getLogoUrlAttribute(): string
{
    return $this->logo_path
        ? Storage::url($this->logo_path)
        : asset('images/default-logo.png');
}

public function getFaviconUrlAttribute(): string
{
    return $this->favicon_path
        ? Storage::url($this->favicon_path)
        : $this->logo_url;
}

public function logoPdfBase64(): string
{
    $path = $this->logo_pdf_path ?? $this->logo_path;
    if ($path && Storage::exists($path)) {
        return base64_encode(Storage::get($path));
    }
    return base64_encode(file_get_contents(public_path('images/default-logo.png')));
}
```

#### Panel de admin para subir logo:
- Nuevo formulario en seccion de configuracion del tenant
- Aceptar PNG/JPG/SVG, max 2MB
- Guardar en `storage/app/public/tenants/{id}/logo.png`
- Generar thumbnail para favicon automaticamente
- Generar version optimizada para PDF

---

### 5.2 Colores Dinamicos

#### Enfoque: CSS Custom Properties inyectadas por tenant

En el `<head>` del layout, inyectar:
```html
<style>
    :root {
        --primary-50: {{ $tenant->color_50 }};
        --primary-100: {{ $tenant->color_100 }};
        --primary-200: {{ $tenant->color_200 }};
        --primary-300: {{ $tenant->color_300 }};
        --primary-400: {{ $tenant->color_400 }};
        --primary-500: {{ $tenant->color_500 }};
        --primary-600: {{ $tenant->color_600 }};
        --primary-700: {{ $tenant->color_700 }};
        --primary-800: {{ $tenant->color_800 }};
        --primary-900: {{ $tenant->color_900 }};
    }
</style>
```

#### Paletas predefinidas disponibles:

| Nombre | 600 (principal) | 700 | 800 | 900 |
|--------|----------------|-----|-----|-----|
| blue | #2563eb | #1d4ed8 | #1e40af | #1e3a8a |
| red | #dc2626 | #b91c1c | #991b1b | #7f1d1d |
| green | #16a34a | #15803d | #166534 | #14532d |
| purple | #9333ea | #7e22ce | #6b21a8 | #581c87 |
| orange | #ea580c | #c2410c | #9a3412 | #7c2d12 |
| teal | #0d9488 | #0f766e | #115e59 | #134e4a |
| indigo | #4f46e5 | #4338ca | #3730a3 | #312e81 |
| pink | #db2777 | #be185d | #9d174d | #831843 |

#### Inventario COMPLETO de clases `blue-*` a reemplazar:

**459 ocurrencias en 44 archivos.** Desglose por archivo:

| Archivo | Ocurrencias | Clases blue-* presentes |
|---------|------------|------------------------|
| `asistencia/create_new.blade.php` | 51 | `focus:border-blue-500`, `focus:ring-blue-500`, `bg-blue-50`, `hover:bg-blue-100`, `text-blue-900`, `border-blue-300`, `bg-blue-600`, `hover:bg-blue-700` |
| `asistencia/create.blade.php` | 51 | (identico al anterior) |
| `asistencia/edit.blade.php` | 50 | (identico) |
| `recuento/index.blade.php` | 44 | `bg-blue-600`, `hover:bg-blue-700`, `text-blue-600`, `text-blue-700`, `bg-blue-50`, `border-blue-200`, `focus:border-blue-500`, `focus:ring-blue-500` |
| `layouts/admin.blade.php` | 25 | `bg-blue-800`, `border-blue-700`, `bg-blue-700`, `bg-blue-900`, `bg-blue-600`, `text-blue-100`, `text-blue-200`, `text-blue-300`, `hover:bg-blue-700/50`, `hover:bg-blue-600`, `hover:bg-blue-500` |
| `recuento/create.blade.php` | 18 | `bg-blue-50`, `text-blue-900`, `focus:border-blue-500`, `focus:ring-blue-500`, `text-blue-600`, `bg-blue-600`, `hover:bg-blue-700` |
| `recuento/partials/resumen-cerrado.blade.php` | 14 | `text-blue-600`, `border-blue-500`, `hover:text-blue-800`, `text-blue-700`, `bg-blue-50`, `border-blue-200` |
| `ingresos-asistencia/asistencia.blade.php` | 12 | `bg-blue-600`, `hover:bg-blue-700`, `text-blue-600`, `focus:border-blue-500`, `focus:ring-blue-500` |
| `ingresos-asistencia/promesas.blade.php` | 11 | Similar |
| `personas/edit.blade.php` | 9 | Similar |
| `personas/create.blade.php` | 9 | Similar |
| `personas/index.blade.php` | 8 | Similar |
| `asistencia/index.blade.php` | 9 | Similar |
| `ingresos-asistencia/index.blade.php` | 8 | Similar |
| `recuento/edit.blade.php` | 8 | Similar |
| `asistencia/show.blade.php` | 7 | Similar |
| `auth/login.blade.php` | 7 | `text-blue-600`, `focus:ring-blue-500`, `hover:border-blue-300`, `bg-blue-700`, `text-blue-100` |
| `principal/index.blade.php` | 7 | `bg-blue-600`, `hover:bg-blue-700`, gradientes blue |
| `mi-perfil/index.blade.php` | 8 | Similar |
| `personas/show.blade.php` | 11 | Similar |
| `promesas/index.blade.php` | 3 | Similar |
| `promesas/edit.blade.php` | 8 | Similar |
| `promesas/create.blade.php` | 6 | Similar |
| `cultos/index.blade.php` | 3 | Similar |
| `cultos/edit.blade.php` | 5 | Similar |
| `cultos/create.blade.php` | 5 | Similar |
| `compromisos/show.blade.php` | 5 | Similar |
| `dashboard.blade.php` | 4 | `text-blue-700`, `text-blue-600` |
| `admin/usuarios/index.blade.php` | 3 | Similar |
| `admin/clases/index.blade.php` | 3 | Similar |
| `admin/clases/edit.blade.php` | 5 | Similar |
| `admin/clases/create.blade.php` | 4 | Similar |
| `layouts/guest.blade.php` | 4 | `text-blue-700`, `bg-blue-600` |
| `admin/usuarios/edit.blade.php` | 1 | `bg-blue-600` |
| `admin/usuarios/create.blade.php` | 1 | `bg-blue-600` |
| `admin/audit/index.blade.php` | 1 | Similar |
| `errors/500.blade.php` | 1 | Similar |
| `errors/404.blade.php` | 1 | Similar |
| `errors/403.blade.php` | 1 | Similar |
| `css/app.css` | 11 | `bg-blue-600`, `hover:bg-blue-700`, `focus:border-blue-500`, `focus:ring-blue-500/20`, `bg-blue-700`, `text-blue-800`, `bg-blue-100`, `hover:bg-blue-50` |

#### Estrategia de reemplazo en CSS (`app.css`):

**ANTES:**
```css
.btn-primary {
    @apply relative bg-blue-600 text-white font-semibold px-6 py-3 rounded-lg;
    @apply hover:bg-blue-700 hover:shadow-md active:bg-blue-800;
}
.stat-card {
    @apply border-l-4 border-l-blue-600;
}
.table-primary thead tr {
    @apply bg-blue-700 text-white;
}
```

**DESPUES:**
```css
.btn-primary {
    @apply relative text-white font-semibold px-6 py-3 rounded-lg;
    background-color: var(--primary-600);
}
.btn-primary:hover {
    background-color: var(--primary-700);
}
.stat-card {
    @apply border-l-4;
    border-left-color: var(--primary-600);
}
.table-primary thead tr {
    background-color: var(--primary-700);
    @apply text-white;
}
```

#### Estrategia de reemplazo en Blade:

**ANTES (ej: sidebar en admin.blade.php):**
```html
<aside class="bg-blue-800 text-white">
    <div class="border-b border-blue-700">
        <div class="bg-white/10 rounded-xl">
    <a class="bg-blue-700 text-white hover:bg-blue-700/50">
```

**DESPUES:**
```html
<aside class="text-white" style="background-color: var(--primary-800)">
    <div style="border-color: var(--primary-700)">
        <div class="bg-white/10 rounded-xl">
    <a class="text-white" style="background-color: var(--primary-700)">
```

**Alternativa mas limpia (clases semanticas en app.css):**
```css
.sidebar { background-color: var(--primary-800); }
.sidebar-border { border-color: var(--primary-700); }
.sidebar-item-active { background-color: var(--primary-700); }
.sidebar-item-hover:hover { background-color: color-mix(in srgb, var(--primary-700) 50%, transparent); }
.sidebar-footer { background-color: var(--primary-900); }
```

#### Colores en PDFs (DomPDF):

Los PDFs usan inline CSS, no Tailwind. Hay que pasar los colores del tenant a cada vista PDF.

**Archivos PDF con colores quemados:**

| Archivo PDF | Lineas con colores | Color actual |
|-------------|-------------------|--------------|
| `pdfs/ingresos.blade.php` | 10, 12, 15, 23, 25, 29 | `#1e40af`, `#2563eb`, `#1d4ed8` |
| `pdfs/recuento-individual.blade.php` | 12, 14, 17, 23, 47 | Mismos azules |
| `pdfs/promesas.blade.php` | 12, 14, 17, 23, 40 | Mismos azules |
| `pdfs/asistencia.blade.php` | 10, 12, 15, 33 | Mismos azules |
| `pdfs/asistencia-culto.blade.php` | 10, 12, 15, 28 | Mismos azules |
| `pdfs/asistencia-mes.blade.php` | 10, 12, 15, 25 | Mismos azules |
| `pdfs/reporte-personas.blade.php` | 34, 51, 63, 66, 73, 83, 84, 508 | Mismos azules |
| `pdfs/reporte-general.blade.php` | varios | Mismos azules |
| `pdfs/reporte-contribuciones.blade.php` | 34, 51, 63, 66, 73, 83, 84, 199 | Mismos azules |

**Solucion:** Pasar `$tenant` a cada vista PDF y usar variables inline:
```html
<style>
    .header { background-color: {{ $tenant->color_800 }}; }
    .header h1 { color: {{ $tenant->color_100 }}; }
    th { background-color: {{ $tenant->color_700 }}; }
</style>
```

---

### 5.3 Nombre de Iglesia Dinamico

#### Inventario de texto "IBBSC" quemado:

| Archivo | Linea | Texto actual | Cambio |
|---------|-------|-------------|--------|
| `layouts/admin.blade.php` | 13 | `'IBBSC - Sistema de Administracion'` | `$tenant->siglas . ' - Admin'` |
| `layouts/admin.blade.php` | 39 | `IBBSC Admin` | `$tenant->siglas . ' Admin'` |
| `layouts/guest.blade.php` | 8 | `IBBSC - Iniciar Sesion` | `$tenant->siglas . ' - Iniciar Sesion'` |
| `layouts/guest.blade.php` | 35 | `IBBSC Admin` | `$tenant->siglas . ' Admin'` |
| `layouts/guest.blade.php` | 48 | `IBBSC. Todos los derechos reservados.` | `$tenant->nombre` (nombre completo en copyright) |
| `principal/index.blade.php` | 3 | `IBBSC - Principal` | `$tenant->siglas . ' - Principal'` |
| `principal/index.blade.php` | 4 | `Iglesia Biblica Bautista Santa Cruz` | `$tenant->nombre` (nombre completo) |
| `principal/index.blade.php` | 14 | `Bienvenido a la Iglesia Biblica Bautista Santa Cruz` | `'Bienvenido a ' . $tenant->nombre` |
| `dashboard.blade.php` | 3 | `IBBSC - Dashboard` | `$tenant->siglas . ' - Dashboard'` |
| `recuento/index.blade.php` | 3 | `IBBSC - Recuento de Sobres` | `$tenant->siglas` |
| `recuento/create.blade.php` | 3 | `IBBSC - Agregar Sobre` | `$tenant->siglas` |
| `recuento/edit.blade.php` | 3 | `IBBSC - Editar Sobre` | `$tenant->siglas` |
| `personas/index.blade.php` | 3 | `IBBSC - Personas` | `$tenant->siglas` |
| `personas/create.blade.php` | 3 | `IBBSC - Nueva Persona` | `$tenant->siglas` |
| `personas/edit.blade.php` | 3 | `IBBSC - Editar Persona` | `$tenant->siglas` |
| `personas/show.blade.php` | 3 | `IBBSC - Detalles de Persona` | `$tenant->siglas` |
| `cultos/index.blade.php` | 3 | `IBBSC - Cultos` | `$tenant->siglas` |
| `cultos/edit.blade.php` | 3 | `IBBSC - Editar Culto` | `$tenant->siglas` |
| `cultos/create.blade.php` | 3 | `IBBSC - Nuevo Culto` | `$tenant->siglas` |
| `promesas/index.blade.php` | 3 | `IBBSC - Promesas` | `$tenant->siglas` |
| `promesas/edit.blade.php` | 3 | `IBBSC - Editar Promesa` | `$tenant->siglas` |
| `promesas/create.blade.php` | 3 | `IBBSC - Nueva Promesa` | `$tenant->siglas` |
| `compromisos/show.blade.php` | 3 | `IBBSC - Compromisos` | `$tenant->siglas` |
| `asistencia/index.blade.php` | 3 | `IBBSC - Asistencia` | `$tenant->siglas` |
| `asistencia/create.blade.php` | 3 | `IBBSC - Nueva Asistencia` | `$tenant->siglas` |
| `asistencia/create_new.blade.php` | 3 | `IBBSC - Nueva Asistencia` | `$tenant->siglas` |
| `asistencia/edit.blade.php` | 3 | `IBBSC - Editar Asistencia` | `$tenant->siglas` |
| `asistencia/show.blade.php` | 3 | `IBBSC - Detalle Asistencia` | `$tenant->siglas` |
| `ingresos-asistencia/index.blade.php` | 3 | `IBBSC - Ingresos y Asistencia` | `$tenant->siglas` |
| `ingresos-asistencia/ingresos.blade.php` | 3 | `IBBSC - Reportes de Ingresos` | `$tenant->siglas` |
| `ingresos-asistencia/promesas.blade.php` | 3 | `IBBSC - Reporte de Promesas` | `$tenant->siglas` |
| `ingresos-asistencia/asistencia.blade.php` | 3 | `IBBSC - Reportes de Asistencia` | `$tenant->siglas` |
| `admin/usuarios/index.blade.php` | 3 | `IBBSC - Gestion de Usuarios` | `$tenant->siglas` |
| `admin/usuarios/edit.blade.php` | 3 | `IBBSC - Editar Usuario` | `$tenant->siglas` |
| `admin/usuarios/create.blade.php` | 3 | `IBBSC - Crear Usuario` | `$tenant->siglas` |
| `admin/clases/index.blade.php` | 3 | `IBBSC - Gestion de Clases` | `$tenant->siglas` |
| `admin/clases/edit.blade.php` | 3 | `IBBSC - Editar Clase` | `$tenant->siglas` |
| `admin/clases/create.blade.php` | 3 | `IBBSC - Nueva Clase` | `$tenant->siglas` |
| `admin/audit/index.blade.php` | 3 | `IBBSC - Auditoria` | `$tenant->siglas` |
| `mi-perfil/index.blade.php` | 3 | `IBBSC - Mi Perfil` | `$tenant->siglas` |

**PDFs con nombre de iglesia quemado:**

| Archivo PDF | Linea(s) | Texto |
|-------------|----------|-------|
| `pdfs/ingresos.blade.php` | 31, 137 | `IBBSC - Iglesia Biblica Bautista en Santa Cruz` |
| `pdfs/recuento-individual.blade.php` | 49, 309 | Idem |
| `pdfs/promesas.blade.php` | 42, 112 | Idem |
| `pdfs/asistencia.blade.php` | 35, 118 | Idem |
| `pdfs/asistencia-culto.blade.php` | 30, 207 | Idem |
| `pdfs/asistencia-mes.blade.php` | 27, 172 | Idem |
| `pdfs/reporte-personas.blade.php` | 6, 669 | `IBBSC - Sistema de Administracion` |
| `pdfs/reporte-general.blade.php` | 5, 404 | Idem |
| `pdfs/reporte-contribuciones.blade.php` | 5, 273 | Idem |

**Total: ~40 referencias en vistas + ~18 referencias en PDFs = ~58 reemplazos de texto "IBBSC"**

---

### 5.4 Redes Sociales Dinamicas

| Archivo | Linea(s) | Referencia actual |
|---------|----------|-------------------|
| `layouts/admin.blade.php` | 172-186 | Seccion "Siguenos" con Instagram + Facebook quemados |
| `principal/index.blade.php` | 91-111 | Seccion "Siguenos en Redes Sociales" con Instagram + Facebook quemados |

**URLs quemadas:**
- `https://www.instagram.com/ibb_santacruz?utm_source=ig_web_button_share_sheet&igsh=ZDNlZDc0MzIxNw==`
- `https://www.facebook.com/iglesia.biblica.bautista.santa.cruz`

**Solucion:** Campo JSON `redes_sociales` en tabla `tenants`:
```php
// En la vista
@if($tenant->redes_sociales)
    @foreach($tenant->redes_sociales as $red => $url)
        @if($red === 'instagram')
            <a href="{{ $url }}">Instagram</a>
        @elseif($red === 'facebook')
            <a href="{{ $url }}">Facebook</a>
        @endif
    @endforeach
@endif
```

---

### 5.5 Simbolo de Moneda Dinamico

#### Inventario completo de `₡` en vistas:

**176 ocurrencias** en los siguientes archivos:

| Archivo | Cant. de `₡` |
|---------|--------------|
| `recuento/index.blade.php` | 30 |
| `recuento/partials/resumen-cerrado.blade.php` | 19 |
| `ingresos-asistencia/ingresos.blade.php` | 30 |
| `ingresos-asistencia/promesas.blade.php` | 12 |
| `ingresos-asistencia/index.blade.php` | 10 |
| `dashboard.blade.php` | 11 |
| `dashboard_old.blade.php` | 11 |
| `recuento/create.blade.php` | 10 |
| `pdfs/reporte-personas.blade.php` | 13 |
| `pdfs/reporte-contribuciones.blade.php` | 6 |
| `compromisos/show.blade.php` | 7 |
| `personas/show.blade.php` | 4 |
| `recuento/edit.blade.php` | 3 |
| `promesas/edit.blade.php` | 3 |
| `promesas/create.blade.php` | 2 |
| `promesas/index.blade.php` | 2 |
| `personas/create.blade.php` | 3 |
| `personas/edit.blade.php` | 3 |
| `mi-perfil/index.blade.php` | 4 |
| `cultos/index.blade.php` | 1 |

**Solucion:** Variable global `$moneda` disponible en todas las vistas via View Composer:
```php
// AppServiceProvider o TenantServiceProvider
View::share('moneda', tenant()->moneda_simbolo ?? '₡');

// En vistas: cambiar ₡ por {{ $moneda }}
// ANTES: ₡{{ number_format($monto, 2) }}
// DESPUES: {{ $moneda }}{{ number_format($monto, 2) }}
```

En JavaScript (dashboard charts):
```javascript
// ANTES: return '₡' + value.toLocaleString();
// DESPUES: return '{{ $moneda }}' + value.toLocaleString();
```

---

## 6. SISTEMA DE RUBROS/CATEGORIAS DINAMICOS

### 6.1 Archivos PHP con categorias quemadas

| Archivo | Linea(s) | Categorias |
|---------|----------|-----------|
| `app/Services/CalculoTotalesCultoService.php` | 16-23, 60-67 | Las 8 categorias como keys de array y en calculo |
| `app/Models/TotalesCulto.php` | 14-22, 30-38 | Las 8 categorias en `$fillable` y `$casts` |
| `app/Http/Controllers/DashboardController.php` | 37-44, 49-55, 61-67, 96 | Totales por categoria, distribucion, filtro diezmo |
| `app/Http/Controllers/PersonaController.php` | 417, 493-494 | Arrays de categorias para reportes |
| `app/Http/Controllers/PromesasReporteController.php` | 97-99, 126-133, 166 | Exclusion diezmo/ofrenda, lista de categorias |
| `app/Http/Controllers/IngresosAsistenciaController.php` | 28-34, 113-120, 135-142, 157-164, 179-186, 263-270, 284-291, 306-313, 350-357, 369-376, 389-396, 438-445, 465-472 | Categorias repetidas ~13 veces en el controlador |

**Total: ~200+ referencias a nombres de categorias en PHP**

### 6.2 Archivos Blade con categorias quemadas

| Archivo | Tipo de quemado |
|---------|----------------|
| `recuento/create.blade.php` (L84-161) | 8 bloques de input con nombre de categoria |
| `recuento/edit.blade.php` (L58) | Array de categorias en PHP inline |
| `recuento/partials/resumen-cerrado.blade.php` (L67-74, 83-89, 96-99, 191-197) | Headers de tabla + variables por categoria |
| `recuento/index.blade.php` (L483-495, 709-716, 807-814) | Totales por categoria x3 secciones |
| `personas/create.blade.php` (L110) | Array de categorias para promesas |
| `personas/edit.blade.php` (L similar) | Idem |
| `personas/index.blade.php` (L235, 272) | Texto descriptivo con nombres de categorias |
| `dashboard.blade.php` (L57-176, 294) | 8 stat cards + labels de Chart.js |
| `ingresos-asistencia/ingresos.blade.php` (L72-97, 125-134) | Headers + celdas de tabla |
| `ingresos-asistencia/index.blade.php` (L75-103) | Cards de resumen por categoria |
| `ingresos-asistencia/promesas.blade.php` | Filtros por categoria |
| Todos los PDFs | Headers de tabla con nombres de categoria |

### 6.3 Solucion para rubros dinamicos

#### En controladores:
```php
// ANTES (quemado):
$categorias = ['misiones', 'seminario', 'campa', 'construccion', 'prestamo', 'micro'];

// DESPUES (dinamico):
$categorias = tenant()->categories()->where('activa', true)->orderBy('orden')->get();
$categoriasPromesa = $categorias->where('excluir_de_promesas', false);
```

#### En CalculoTotalesCultoService:
```php
// ANTES: array fijo de 8 totales
// DESPUES: query dinamica
public function recalcular(Culto $culto): TotalesCulto
{
    $categorias = $culto->tenant->categories()->where('activa', true)->get();

    $totalesPorCategoria = SobreDetalle::whereHas('sobre', fn($q) => $q->where('culto_id', $culto->id))
        ->selectRaw('category_id, SUM(monto) as total')
        ->groupBy('category_id')
        ->pluck('total', 'category_id');

    $totales = TotalesCulto::updateOrCreate(
        ['culto_id' => $culto->id],
        ['total_suelto' => ..., 'total_general' => ...]
    );

    // Guardar detalles por categoria
    foreach ($categorias as $cat) {
        TotalesCultoDetalle::updateOrCreate(
            ['totales_culto_id' => $totales->id, 'category_id' => $cat->id],
            ['monto' => $totalesPorCategoria[$cat->id] ?? 0]
        );
    }
}
```

#### En vistas (ejemplo recuento/create.blade.php):
```blade
{{-- ANTES: 8 bloques de input quemados --}}

{{-- DESPUES: loop dinamico --}}
@foreach($categorias as $categoria)
<div>
    <label>{{ $categoria->nombre }}</label>
    <div class="relative">
        <span>{{ $moneda }}</span>
        <input type="number" name="categorias[{{ $categoria->id }}]"
               step="0.01" min="0" value="0">
    </div>
</div>
@endforeach
```

#### En dashboard (Chart.js):
```javascript
// ANTES: labels quemados
labels: ['Diezmo', 'Misiones', 'Seminario', ...]

// DESPUES: desde PHP
labels: {!! json_encode($categorias->pluck('nombre')) !!},
data: {!! json_encode($distribucion->values()) !!}
```

---

## 7. SISTEMA DE ASISTENCIA DINAMICA

### 7.1 Columnas quemadas actuales en tabla `asistencia`

**Grupo Capilla (8 columnas):**
- `chapel_adultos_hombres`, `chapel_adultos_mujeres`
- `chapel_adultos_mayores_hombres`, `chapel_adultos_mayores_mujeres`
- `chapel_jovenes_masculinos`, `chapel_jovenes_femeninas`
- `chapel_maestros_hombres`, `chapel_maestros_mujeres`

**4 grupos de clases (16 columnas, 4 por grupo):**
- `clase_0_1_hombres`, `clase_0_1_mujeres`, `clase_0_1_maestros_hombres`, `clase_0_1_maestros_mujeres`
- `clase_2_6_*` (x4), `clase_7_8_*` (x4), `clase_9_11_*` (x4)

**Salvos, Bautismos, Visitas (18 columnas, 6 por grupo):**
- `salvos_adulto_hombre`, `salvos_adulto_mujer`, `salvos_joven_hombre`, `salvos_joven_mujer`, `salvos_nino`, `salvos_nina`
- `bautismos_*` (x6), `visitas_*` (x6)

**Total: ~42 columnas quemadas**

### 7.2 Archivos afectados

| Archivo | Impacto |
|---------|---------|
| `app/Models/Asistencia.php` | `$fillable` con ~42 campos |
| `app/Http/Controllers/AsistenciaController.php` | Validacion store/update con ~42 campos |
| `asistencia/create.blade.php` | ~42 selects quemados (~500 lineas) |
| `asistencia/create_new.blade.php` | Identico |
| `asistencia/edit.blade.php` | Identico |
| `asistencia/show.blade.php` | Tabla con todos los campos |
| Todos los PDFs de asistencia | Tablas con columnas fijas |
| `IngresosAsistenciaController.php` | Calculos de totales |

### 7.3 Solucion

Los formularios pasan de ~42 campos quemados a loops dinamicos:

```blade
@foreach($grupos as $grupo)
<div class="border rounded-lg">
    <button onclick="toggleSection('{{ $grupo->slug }}')">
        <h3>{{ $grupo->nombre }}</h3>
    </button>
    <div id="section-{{ $grupo->slug }}">
        @foreach($grupo->fields as $field)
        <div>
            <label>{{ $field->nombre }}</label>
            <select name="fields[{{ $field->id }}]">
                @for($i = 0; $i <= 200; $i++)
                    <option value="{{ $i }}">{{ $i }}</option>
                @endfor
            </select>
        </div>
        @endforeach
    </div>
</div>
@endforeach
```

---

## 8. AUTENTICACION POR DOMINIO DE EMAIL

### 8.1 Flujo de registro/login

```
1. Usuario llega a /login
2. Ingresa email: juan@ibbla.com
3. Sistema extrae dominio: "ibbla.com"
4. Busca en tenant_email_domains WHERE dominio = 'ibbla.com'
5. Si encuentra → asigna tenant_id al user, redirige a dashboard del tenant
6. Si dominio es "admin.com" → redirige a panel super admin
7. Si no encuentra → error "Dominio no registrado. Contacte al administrador."
```

### 8.2 Archivos a modificar

| Archivo | Cambio |
|---------|--------|
| `app/Http/Controllers/Auth/RegisteredUserController.php` | Validar dominio de email, asignar tenant_id |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | Resolver tenant al login, setear en sesion |
| `resources/views/auth/login.blade.php` | Mostrar branding del tenant si se conoce (o branding generico) |
| `resources/views/auth/register.blade.php` | Idem |

### 8.3 Middleware nuevo: `ResolveTenant`
```php
class ResolveTenant
{
    public function handle($request, $next)
    {
        if (auth()->check()) {
            $user = auth()->user();
            if ($user->is_super_admin) {
                // No resolver tenant, dar acceso super admin
                app()->instance('tenant', null);
            } else {
                $tenant = Tenant::find($user->tenant_id);
                if (!$tenant || !$tenant->activo) {
                    abort(403, 'Iglesia no activa');
                }
                app()->instance('tenant', $tenant);
            }
        }
        return $next($request);
    }
}
```

---

## 9. PANEL DE SUPER ADMIN

### 9.0 Identidad visual del panel Super Admin

El panel de Super Admin **NO pertenece a ninguna iglesia**, por lo tanto:

- **Sin logo**: No se muestra logo en el sidebar ni en el header. Solo un icono generico (escudo, engranaje, o similar).
- **Titulo fijo**: El sidebar muestra **"Panel Super Admin"** en vez de nombre/siglas de iglesia.
- **Colores fijos**: Usa una paleta neutra propia (gris oscuro / slate) que NO depende de ningun tenant. No usa CSS variables de tenant.
- **Sin redes sociales**: No hay seccion de "Siguenos" en el sidebar.
- **Favicon propio**: Un favicon generico de la plataforma, no de ninguna iglesia.
- **Titulo de pagina**: `"Super Admin - [Nombre de seccion]"` (ej: "Super Admin - Iglesias", "Super Admin - Dashboard").

```html
<!-- Sidebar del Super Admin (simplificado) -->
<aside class="bg-slate-800 text-white">
    <div class="flex items-center gap-3 px-6 h-20 border-b border-slate-700">
        <div class="w-10 h-10 bg-slate-600 rounded-lg flex items-center justify-center">
            <svg><!-- icono de escudo/admin --></svg>
        </div>
        <h1 class="text-lg font-bold">Panel Super Admin</h1>
    </div>
    <nav>
        <a href="/super-admin">Dashboard</a>
        <a href="/super-admin/tenants">Iglesias</a>
        <a href="/super-admin/tenants/create">Nueva Iglesia</a>
    </nav>
</aside>
```

### 9.1 Formulario de creacion de iglesia (campos obligatorios)

Al crear una nueva iglesia desde el Super Admin, se deben completar **todos estos campos**:

| Campo | Tipo | Requerido | Ejemplo | Notas |
|-------|------|-----------|---------|-------|
| **Nombre completo** | text | Si | "Iglesia Biblica Bautista Los Angeles" | Nombre completo de la iglesia |
| **Siglas** | text (max 20) | Si | "IBBLA" | Se muestra en sidebar, titulos de pagina, PDFs |
| **Logo** | file (imagen) | Si | logo.png | Se usa en sidebar, pagina principal, PDFs |
| **Color principal** | color picker o select | Si | `#2563eb` (azul) | Genera paleta 50-900 automaticamente, o elige paleta predefinida |
| **Redes sociales** | multiples inputs | No | Instagram URL, Facebook URL, YouTube URL | Se muestran en sidebar y pagina principal |
| **Dominio(s) de email** | text (multiples) | Si (al menos 1) | "ibbla.com" | Para autenticacion por email |
| **Moneda** | select | Si | CRC / ₡ | Simbolo que aparece en toda la app |
| **Timezone** | select | Si | America/Costa_Rica | Para fechas y horas |
| **Contacto** | text | No | telefono, direccion, email | Info de la iglesia |

**Flujo del formulario:**
```
Paso 1: Datos basicos (nombre, siglas, dominio email)
Paso 2: Branding (logo, color)
Paso 3: Configuracion (moneda, timezone, redes sociales)
→ Se crea el tenant + se seedean roles default + categorias default
→ Se crea usuario admin de la iglesia automaticamente con el dominio registrado
```

**Donde aparecen las siglas vs. el nombre completo:**

| Lugar | Usa siglas | Usa nombre completo |
|-------|-----------|-------------------|
| Sidebar header (ej: "IBBLA Admin") | Si | No |
| Titulo de pagina `<title>` (ej: "IBBLA - Dashboard") | Si | No |
| Pagina principal / bienvenida | No | Si ("Bienvenido a Iglesia Biblica Bautista Los Angeles") |
| PDFs - header | Si (siglas) | Si (nombre completo debajo) |
| PDFs - footer | Si | No |
| Copyright footer login | No | Si |

### 9.2 Nuevas rutas

```
/super-admin/                        → Dashboard global (cuantas iglesias, usuarios totales, etc.)
/super-admin/tenants                 → Lista de iglesias con filtros
/super-admin/tenants/create          → Formulario de nueva iglesia (pasos 1-3)
/super-admin/tenants/{id}/edit       → Editar datos basicos de iglesia
/super-admin/tenants/{id}/branding   → Editar logo y colores
/super-admin/tenants/{id}/categories → Gestionar rubros de esa iglesia
/super-admin/tenants/{id}/users      → Ver/gestionar usuarios de esa iglesia
/super-admin/tenants/{id}/domains    → Gestionar dominios de email
/super-admin/tenants/{id}/toggle     → Activar/desactivar iglesia
```

### 9.3 Nuevos archivos necesarios

| Tipo | Archivo |
|------|---------|
| Controller | `app/Http/Controllers/SuperAdmin/TenantController.php` |
| Controller | `app/Http/Controllers/SuperAdmin/DashboardController.php` |
| Middleware | `app/Http/Middleware/SuperAdminMiddleware.php` |
| Vista | `resources/views/super-admin/layout.blade.php` (layout propio, sin branding de tenant) |
| Vista | `resources/views/super-admin/dashboard.blade.php` |
| Vista | `resources/views/super-admin/tenants/index.blade.php` |
| Vista | `resources/views/super-admin/tenants/create.blade.php` (formulario multi-paso) |
| Vista | `resources/views/super-admin/tenants/edit.blade.php` |
| Vista | `resources/views/super-admin/tenants/branding.blade.php` |
| Vista | `resources/views/super-admin/tenants/categories.blade.php` |
| Vista | `resources/views/super-admin/tenants/domains.blade.php` |
| Vista | `resources/views/super-admin/tenants/users.blade.php` |

---

## 10. MODIFICACIONES POR ARCHIVO (INVENTARIO COMPLETO)

### 10.1 Modelos (app/Models/)

| Archivo | Cambios |
|---------|---------|
| `User.php` | Agregar `tenant_id`, `tenant_role_id`, `is_super_admin`. Eliminar metodos `isAdmin()` etc. basados en ENUM. Nuevo: relacion `belongsTo(Tenant)`, `belongsTo(TenantRole)`. Agregar Global Scope de tenant. |
| `Tenant.php` | **NUEVO**. Relaciones: hasMany Categories, ServiceTypes, DemographicGroups, Users, Roles, EmailDomains. Accessors para logo_url, favicon_url, logoPdfBase64. |
| `TenantCategory.php` | **NUEVO**. belongsTo Tenant. |
| `TenantServiceType.php` | **NUEVO**. belongsTo Tenant. |
| `TenantDemographicGroup.php` | **NUEVO**. belongsTo Tenant, hasMany Fields. |
| `TenantDemographicField.php` | **NUEVO**. belongsTo Group. |
| `TenantRole.php` | **NUEVO**. belongsTo Tenant, hasMany Users. |
| `TenantEmailDomain.php` | **NUEVO**. belongsTo Tenant. |
| `Persona.php` | Agregar `tenant_id`, Global Scope de tenant. |
| `Culto.php` | Agregar `tenant_id`, `service_type_id`. Eliminar ENUM tipo_culto. Global Scope. |
| `Sobre.php` | Sin cambio directo (o agregar tenant_id para queries). |
| `SobreDetalle.php` | Cambiar `categoria` string por `category_id` FK. |
| `OfrendaSuelta.php` | Sin cambio (hereda tenant del culto). |
| `Egreso.php` | Sin cambio. |
| `TotalesCulto.php` | Eliminar campos de categorias del `$fillable` y `$casts`. Nueva relacion hasMany TotalesCultoDetalle. |
| `TotalesCultoDetalle.php` | **NUEVO**. belongsTo TotalesCulto, belongsTo TenantCategory. |
| `Asistencia.php` | Eliminar ~42 campos de `$fillable`. Nueva relacion hasMany AsistenciaDetalle. |
| `AsistenciaDetalle.php` | **NUEVO**. belongsTo Asistencia, belongsTo TenantDemographicField. |
| `Promesa.php` | Cambiar `categoria` string por `category_id` FK. |
| `Compromiso.php` | Cambiar `categoria` string por `category_id` FK. Modificar constraint UNIQUE. |
| `ClaseAsistencia.php` | Agregar `tenant_id`. |
| `AuditLog.php` | Agregar `tenant_id`. |

### 10.2 Controladores (app/Http/Controllers/)

| Archivo | Cambios |
|---------|---------|
| `DashboardController.php` | Reescribir totales para usar TotalesCultoDetalle en vez de columnas fijas. Distribucion dinamica desde categorias del tenant. |
| `RecuentoController.php` | Pasar `$categorias` del tenant a vistas create/edit. Ajustar store/update para guardar por category_id en vez de string. |
| `PersonaController.php` | Cargar categorias del tenant. Cambiar arrays quemados por query. Ajustar reportePdf y reporteGeneral. |
| `PromesasReporteController.php` | Reemplazar filtros quemados de diezmo/ofrenda por propiedad `excluir_de_promesas` de la categoria. |
| `AsistenciaController.php` | Reescribir store/update para usar AsistenciaDetalle en vez de ~42 campos. Cargar grupos demograficos del tenant. |
| `CultoController.php` | Usar ServiceTypes del tenant en vez de ENUM. |
| `IngresosAsistenciaController.php` | Reescribir TODAS las queries que referencian categorias quemadas (~13 bloques). |
| `CompromisoController.php` | Ajustar para category_id. |
| `MiPerfilController.php` | Ajustar para categorias dinamicas. |
| `Auth/RegisteredUserController.php` | Resolver tenant por dominio de email. |
| `Auth/AuthenticatedSessionController.php` | Resolver tenant al login. |

### 10.3 Servicios (app/Services/)

| Archivo | Cambios |
|---------|---------|
| `CalculoTotalesCultoService.php` | **Reescritura total.** Cambiar de columnas fijas a TotalesCultoDetalle. Loop por categorias del tenant. |

### 10.4 Middleware (app/Http/Middleware/)

| Archivo | Cambios |
|---------|---------|
| `CheckRole.php` | Cambiar de comparar string ENUM a verificar permisos en TenantRole. |
| `ResolveTenant.php` | **NUEVO.** Resolver tenant del usuario autenticado, setear en app container. |
| `SuperAdminMiddleware.php` | **NUEVO.** Verificar `is_super_admin`. |
| `AuditLogMiddleware.php` | Agregar `tenant_id` al log. |

### 10.5 Vistas - Layouts

| Archivo | Cambios especificos |
|---------|-------------------|
| `layouts/admin.blade.php` | L13: titulo dinamico. L16-18: favicon dinamico. L37: logo dinamico. L39: nombre dinamico. L33-143: ~25 clases blue→CSS vars. L172-186: redes sociales dinamicas. L198: logo dinamico. Inyectar CSS vars en `<head>`. |
| `layouts/guest.blade.php` | L8: titulo dinamico. L11-13: favicon dinamico. L33: logo dinamico. L35: nombre dinamico. L48: copyright dinamico. ~4 clases blue. |
| `layouts/app.blade.php` | L11-13: favicon dinamico. |

### 10.6 Vistas - Cada pagina

**(Se aplica a TODAS las vistas listadas en la seccion 5.3 para el titulo, mas las siguientes para contenido especifico):**

| Vista | Cambios adicionales |
|-------|-------------------|
| `recuento/create.blade.php` | L84-161: 8 inputs quemados → loop @foreach. L86-156: 8x `₡` → `{{ $moneda }}`. L168, 215: JS con `₡`. |
| `recuento/edit.blade.php` | L58: array de categorias → foreach. L69, 85, 123: `₡`. |
| `recuento/index.blade.php` | L483-495: totales → loop. L709-716, 807-814: tablas → foreach. 30x `₡`. 44 clases blue. |
| `recuento/partials/resumen-cerrado.blade.php` | L67-74: headers → foreach. L83-99: variables → loop. L191-197: totales → foreach. 19x `₡`. |
| `dashboard.blade.php` | L57-176: 8 stat cards → foreach. L294: labels Chart.js → dinamico. 11x `₡`. |
| `personas/create.blade.php` | L110: array categorias → foreach. 3x `₡`. |
| `personas/edit.blade.php` | Similar a create. 3x `₡`. |
| `personas/show.blade.php` | Promesas por categoria → foreach. 4x `₡`. |
| `personas/index.blade.php` | L235, 272: textos descriptivos → dinamico. |
| `asistencia/create.blade.php` | ~500 lineas de selects quemados → ~30 lineas con foreach. |
| `asistencia/create_new.blade.php` | Identico. |
| `asistencia/edit.blade.php` | Identico. |
| `asistencia/show.blade.php` | Tabla quemada → foreach. |
| `ingresos-asistencia/ingresos.blade.php` | Headers tabla + celdas → foreach. 30x `₡`. |
| `ingresos-asistencia/index.blade.php` | Cards por categoria → foreach. 10x `₡`. |
| `ingresos-asistencia/promesas.blade.php` | Filtros → dinamico. 12x `₡`. |
| `promesas/create.blade.php` | Selector de categoria → opciones dinamicas. 2x `₡`. |
| `promesas/edit.blade.php` | Idem. 3x `₡`. |
| `compromisos/show.blade.php` | Tabla → foreach. 7x `₡`. |
| `mi-perfil/index.blade.php` | Cards → foreach. 4x `₡`. |
| `principal/index.blade.php` | Nombre iglesia, redes sociales → dinamico. |
| `auth/login.blade.php` | Logo, colores de pantalla de carga → dinamico. |
| `cultos/create.blade.php` | Tipo de culto: ENUM → selector de ServiceTypes. |
| `cultos/edit.blade.php` | Idem. |

### 10.7 PDFs

Cada PDF necesita:
1. Recibir `$tenant` como variable
2. Logo → `$tenant->logoPdfBase64()`
3. Nombre → `$tenant->nombre`
4. Colores → variables inline
5. Categorias → foreach (donde aplique)
6. Moneda → `$tenant->moneda_simbolo`

| PDF | Cambios |
|-----|---------|
| `pdfs/ingresos.blade.php` | Logo, nombre, colores, headers de tabla → foreach, moneda |
| `pdfs/recuento-individual.blade.php` | Idem |
| `pdfs/promesas.blade.php` | Idem |
| `pdfs/promesas-anual.blade.php` | Idem |
| `pdfs/asistencia.blade.php` | Logo, nombre, colores, campos demograficos → foreach |
| `pdfs/asistencia-culto.blade.php` | Idem |
| `pdfs/asistencia-mes.blade.php` | Idem |
| `pdfs/reporte-personas.blade.php` | Idem + categorias dinamicas |
| `pdfs/reporte-general.blade.php` | Idem |
| `pdfs/reporte-contribuciones.blade.php` | Idem |

### 10.8 Configuracion

| Archivo | Cambio |
|---------|--------|
| `config/app.php` | Timezone y locale se mantienen como fallback, pero tenant los overridea |
| `tailwind.config.js` | Sin cambio obligatorio (CSS vars no dependen de Tailwind) |
| `resources/css/app.css` | Refactor de ~11 clases con `blue-*` → CSS vars |
| `bootstrap/app.php` | Registrar nuevos middleware: ResolveTenant, SuperAdmin |
| `routes/web.php` | Agregar grupo de rutas super-admin. Roles pasan de strings a permisos. |
| `database/seeders/AdminUserSeeder.php` | Crear tenant default + domain + categories + roles |

---

## 11. MIGRACIONES NECESARIAS

En orden de ejecucion:

```
1. create_tenants_table
2. create_tenant_email_domains_table
3. create_tenant_categories_table
4. create_tenant_service_types_table
5. create_tenant_demographic_groups_table
6. create_tenant_demographic_fields_table
7. create_tenant_roles_table
8. add_tenant_id_to_users_table
9. add_tenant_id_to_personas_table
10. add_tenant_id_to_cultos_table
11. add_service_type_id_to_cultos_table
12. modify_sobre_detalles_categoria_to_category_id
13. create_totales_culto_detalle_table
14. remove_category_columns_from_totales_culto
15. create_asistencia_detalle_table
16. remove_demographic_columns_from_asistencia
17. modify_promesas_categoria_to_category_id
18. modify_compromisos_categoria_to_category_id
19. add_tenant_id_to_clases_asistencia_table
20. add_tenant_id_to_audit_logs_table
```

**Nota critica:** Las migraciones 14 y 16 (eliminar columnas) deben ejecutarse DESPUES de migrar datos existentes a las nuevas tablas pivot. Se necesita un seeder/comando de migracion de datos.

---

## 12. NUEVOS ARCHIVOS A CREAR

### Modelos (~10)
- `Tenant.php`, `TenantCategory.php`, `TenantServiceType.php`
- `TenantDemographicGroup.php`, `TenantDemographicField.php`
- `TenantRole.php`, `TenantEmailDomain.php`
- `TotalesCultoDetalle.php`, `AsistenciaDetalle.php`

### Controladores (~3)
- `SuperAdmin/TenantController.php`
- `SuperAdmin/DashboardController.php`
- `Tenant/SettingsController.php` (para que admin de iglesia edite logo/colores)

### Middleware (~2)
- `ResolveTenant.php`
- `SuperAdminMiddleware.php`

### Vistas (~12)
- `super-admin/layout.blade.php`
- `super-admin/dashboard.blade.php`
- `super-admin/tenants/index.blade.php`
- `super-admin/tenants/create.blade.php`
- `super-admin/tenants/edit.blade.php`
- `super-admin/tenants/branding.blade.php`
- `super-admin/tenants/categories.blade.php`
- `super-admin/tenants/domains.blade.php`
- `super-admin/tenants/users.blade.php`
- `tenant-settings/branding.blade.php` (para admin de iglesia)
- `tenant-settings/categories.blade.php`
- `tenant-settings/general.blade.php`

### Migraciones (~20)
(Listadas en seccion 11)

### Seeders (~3)
- `TenantSeeder.php` (crear tenant default con datos de IBBSC)
- `TenantCategoriesSeeder.php` (migrar categorias actuales)
- `TenantRolesSeeder.php` (crear roles default)

### Service Providers (~1)
- `TenantServiceProvider.php` (View::share, Global Scopes, helper `tenant()`)

### Traits (~1)
- `BelongsToTenant.php` (Global Scope reutilizable)

---

## 13. ORDEN DE IMPLEMENTACION

### Fase 1: Fundacion Multi-Tenant (sin cambiar funcionalidad)
1. Crear tabla `tenants` y modelo
2. Crear tabla `tenant_email_domains`
3. Agregar `tenant_id` a `users`, `personas`, `cultos`, etc.
4. Crear trait `BelongsToTenant` con Global Scope
5. Crear middleware `ResolveTenant`
6. Crear `TenantServiceProvider` con View::share del tenant
7. Crear seeder que cree tenant default con datos de IBBSC
8. **Test:** App funciona exactamente igual con un solo tenant

### Fase 2: Branding Dinamico
9. Agregar campos de branding a `tenants`
10. Inyectar CSS Custom Properties en layouts
11. Refactorear `app.css` (11 clases)
12. Refactorear `layouts/admin.blade.php` (25 clases + logo + nombre + redes)
13. Refactorear `layouts/guest.blade.php`
14. Refactorear todas las vistas: titulos (40 archivos, linea 3 de cada uno)
15. Refactorear todos los `₡` → `{{ $moneda }}` (176 ocurrencias)
16. Refactorear logos en PDFs (10 archivos)
17. Refactorear colores en PDFs (10 archivos)
18. Refactorear nombres en PDFs (10 archivos)
19. Refactorear todas las clases `blue-*` en vistas (459 ocurrencias, 44 archivos)
20. **Test:** Cambiar colores/logo del tenant, verificar que todo se actualiza

### Fase 3: Rubros Dinamicos
21. Crear tabla `tenant_categories` y modelo
22. Crear tabla `totales_culto_detalle`
23. Migrar `sobre_detalles.categoria` → `category_id`
24. Migrar `promesas.categoria` → `category_id`
25. Migrar `compromisos.categoria` → `category_id`
26. Reescribir `CalculoTotalesCultoService`
27. Reescribir `DashboardController`
28. Reescribir `IngresosAsistenciaController` (13 bloques)
29. Reescribir `PersonaController` reportes
30. Reescribir `PromesasReporteController`
31. Refactorear vistas de recuento (create, edit, index, resumen-cerrado)
32. Refactorear dashboard.blade.php
33. Refactorear todas las vistas de ingresos/reportes
34. Refactorear todos los PDFs con categorias
35. Eliminar columnas fijas de `totales_culto`
36. **Test:** Crear/quitar rubros, verificar que flujos completos funcionan

### Fase 4: Asistencia Dinamica
37. Crear tablas `tenant_demographic_groups`, `tenant_demographic_fields`, `asistencia_detalle`
38. Reescribir `AsistenciaController`
39. Reescribir formularios de asistencia (create, edit, show)
40. Reescribir PDFs de asistencia
41. Eliminar ~42 columnas de tabla `asistencia`
42. **Test:** Configurar grupos demograficos diferentes, verificar flujos

### Fase 5: Tipos de Culto Dinamicos
43. Crear tabla `tenant_service_types`
44. Modificar `cultos` tabla y modelo
45. Modificar `CultoController`
46. Modificar vistas de cultos (create, edit)
47. **Test:** Tipos de culto personalizados funcionan

### Fase 6: Roles Dinamicos
48. Crear tabla `tenant_roles`
49. Modificar `User` modelo
50. Reescribir `CheckRole` middleware
51. Refactorear sidebar (admin.blade.php) para permisos dinamicos
52. **Test:** Roles personalizados funcionan

### Fase 7: Panel Super Admin
53. Crear middleware `SuperAdminMiddleware`
54. Crear layout y vistas de super admin
55. CRUD de tenants
56. Gestion de dominios de email
57. Gestion de categorias/rubros por tenant
58. **Test:** Crear nueva iglesia desde panel, verificar aislamiento

### Fase 8: Auth por Email Domain
59. Modificar registro para resolver tenant por dominio
60. Modificar login para setear tenant
61. Manejar caso "dominio no registrado"
62. **Test:** Flujo completo de registro → login → vista correcta del tenant

---

## 14. RIESGOS Y DECISIONES PENDIENTES

### Decisiones por tomar:
1. **Paletas de colores**: Custom libre (color picker) vs. paletas predefinidas vs. ambas?
2. **Migracion de datos existentes**: Migrar datos de IBBSC actual al nuevo schema o empezar limpio?
3. **Subdominio vs. dominio de email**: Cada iglesia en `ibbsc.miapp.com` vs. solo por email?
4. **Limites por plan**: Cuantos usuarios/rubros/etc. puede tener cada iglesia?
5. **Frecuencias de promesas**: Mantener ENUM o hacer dinamico por tenant?
6. **Metodos de pago**: Mantener ENUM (efectivo/transferencia) o hacer dinamico?

### Riesgos principales:
1. **Performance**: Global Scopes agregan WHERE a cada query. Con muchos tenants puede impactar. Mitigacion: indices en `tenant_id`.
2. **Aislamiento de datos**: Un bug en el Global Scope podria exponer datos entre iglesias. Mitigacion: tests exhaustivos, middleware de verificacion doble.
3. **PDFs**: DomPDF no soporta CSS variables. Hay que pasar los colores como valores inline directamente.
4. **Compatibilidad de colores**: Colores custom pueden verse mal (ej: texto blanco sobre fondo amarillo claro). Mitigacion: paletas predefinidas que garanticen contraste.

### Estadisticas finales del refactor:

| Metrica | Cantidad |
|---------|----------|
| Archivos a modificar | ~65 |
| Archivos nuevos a crear | ~45 |
| Migraciones nuevas | ~20 |
| Clases blue-* a reemplazar | 459 en 44 archivos |
| Simbolos ₡ a parametrizar | 176 |
| Textos "IBBSC" a reemplazar | ~58 |
| Logos a dinamizar | 25 |
| URLs de redes sociales | 4 |
| Columnas de BD a eliminar | ~50 (asistencia) + 8 (totales) |
| Nuevas tablas | 9 |
| Nuevos modelos | ~10 |
| Controladores a reescribir significativamente | 7 |

---

*Documento generado el 2026-02-09 por analisis exhaustivo del codebase IBBSCation.*
