# Zonas de la red institucional

`/catalogos/zonas-red` administra el catálogo existente `dbo.catcZonasRed` mediante `dbo.sp_catcZonasRed` y las rutas genéricas de ReferenceCatalogController. Permite consultar, buscar, agregar y editar nombre y descripción, activar y desactivar. Cuando el catálogo está vacío, muestra un mensaje y el botón Agregar primera zona. No carga datos de ejemplo.

No se muestra ni se expone un catálogo de zonas del firewall. Las zonas `lan` e `inet` recibidas de Palo Alto no se incorporan a este catálogo institucional. La tabla institucional no se modifica estructuralmente.

Las rutas requieren sesión de administrador y las escrituras utilizan CSRF. La desactivación conserva los registros y sus asignaciones históricas.
