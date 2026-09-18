# Entornos aislados

## Propósito

`compose.isolated.yaml` crea entornos locales que no comparten base de datos,
volúmenes, red, gateway, workers ni scheduler con Producción. En particular,
el gateway de Restaurant queda bloqueado dentro de estos entornos.

| Entorno | URL | Proyecto Docker | Base de datos |
| --- | --- | --- | --- |
| Desarrollo | `http://localhost:8091` | `crm-dimsum-development` | `crm_dimsum_development` |
| Staging local | `http://localhost:8092` | `crm-dimsum-staging` | `crm_dimsum_staging` |
| Producción | VPS | `crm-dimsum` | Solo VPS |

## Inicio

Desde la raíz del repositorio:

```powershell
.\scripts\Start-IsolatedEnvironment.ps1 -Environment development
.\scripts\Start-IsolatedEnvironment.ps1 -Environment staging
```

En el primer inicio se crea un archivo `.env.development` o `.env.staging`
ignorado por Git. Allí se generan las credenciales del administrador aislado;
no se reutilizan credenciales de Producción.

Para detener un entorno y conservar su información local:

```powershell
.\scripts\Stop-IsolatedEnvironment.ps1 -Environment development
```

## Reglas operativas

- No ejecutar `compose.yaml` para pruebas locales: representa el stack operativo
  y puede incluir gateway, workers y scheduler.
- No copiar ni importar una base de Producción al entorno aislado sin una
  anonimización y aprobación explícitas.
- Cambios funcionales: validar primero en Desarrollo, luego en Staging local;
  después usar el flujo GitHub y `scripts/deploy-production.ps1` para Producción.
- Los `.env*` operativos no se versionan. Los archivos `*.example` son solo
  plantillas sin credenciales.
