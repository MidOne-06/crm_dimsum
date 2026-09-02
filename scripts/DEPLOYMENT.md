# Despliegue verificable a producción

El código que se prueba debe ser exactamente el que llega a producción. No se
deben copiar archivos aislados ni reconstruir Docker desde una carpeta remota
que no se haya sincronizado.

## Flujo obligatorio

1. Revise y confirme los cambios en Git de `opm-digemid` y, cuando aplique,
   de `API-TI`.
2. Asegure que ambos repositorios estén limpios: `git status --short` no debe
   mostrar archivos.
3. Ejecute desde PowerShell:

   ```powershell
   .\scripts\deploy-production.ps1
   ```

4. El script crea paquetes Git inmutables, sincroniza el CRM y el gateway,
   reconstruye los contenedores, reinicia app/scheduler/gateway y revisa su
   estado HTTP.
5. Ejecute la comprobación posterior:

   ```powershell
   .\scripts\verify-production.ps1
   ```

   Debe terminar sin diferencias de hash.

## Reglas

- El script usa la autenticación SSH configurada en el equipo; no guarda
  contraseñas ni secretos.
- Si se cambia solo CRM, puede usarse `-SkipGateway`.
- Para publicar una revisión específica: `-CrmRef <commit>` y
  `-GatewayRef <commit>`.
- No se declara un cambio terminado sin la verificación de hashes y una prueba
  funcional en la pantalla afectada.
