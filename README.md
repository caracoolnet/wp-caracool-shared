# wp-caracool-shared

Piezas compartidas entre los plugins de WordPress de la casa (Caracool Motion,
Caracool Carta, Caracool OneStep, Caracool Churra). **No es un plugin
instalable** — es la fuente de verdad de los archivos que tienen que ser
idénticos, byte a byte, en cada uno.

## Qué hay aquí

- `inc/caracool-menu.php` — el menú de admin compartido "Caracool". El primer
  plugin que carga crea el menú padre en la barra lateral de wp-admin; los
  demás se cuelgan de él como submenús, en cualquier orden y con cualquier
  combinación instalada. Ningún plugin depende de los demás: si es el único
  instalado, sigue funcionando igual, creando él mismo el menú padre.

## Cómo se usa

Cada plugin de la casa trae su propia copia de estos archivos en su carpeta
`inc/`. Antes de publicar una versión nueva de cualquiera de ellos, se
compara su copia con la de aquí y se sustituye si no coincide, para que las
copias no se vayan desincronizando con el tiempo.

Ver la cabecera de `inc/caracool-menu.php` para cómo engancharlo desde un
plugin nuevo (`require_once`, `add_submenu_page` y el filtro
`caracool_plugins`).

## Qué puede llegar aquí más adelante

Pendiente de decidir: el CSS del panel de ajustes (hoy copiado en cada
plugin) y el comprobador de actualizaciones contra GitHub, si se quiere
evitar que cambiar el diseño del panel implique tocar cuatro plugins a la
vez.
