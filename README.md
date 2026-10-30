# wp-caracool-shared

Piezas compartidas entre los plugins de WordPress de la casa (Vinos, Eventos,
Motion, Nueva Visión, OneStep, Toneles, HostPV y Bono regalo). **No es un plugin
instalable** — es la fuente de verdad de los archivos que tienen que ser
idénticos, byte a byte, en cada uno.

## Qué hay aquí

- `inc/caracool-menu.php` — el menú de admin compartido "Caracool". El primer
  plugin que carga crea el menú padre en la barra lateral de wp-admin; los
  demás se cuelgan de él como submenús, en cualquier orden y con cualquier
  combinación instalada. Ningún plugin depende de los demás: si es el único
  instalado, sigue funcionando igual, creando él mismo el menú padre.
- `inc/caracool-actualizador.php` — el actualizador común (1.1.0). Con la
  licencia de la web (Caracool → Licencia, o la constante `CARACOOL_LICENCIA`)
  pregunta a Caracool Central (updates.caracool.net), que es quien baja el zip
  de GitHub; así los repositorios pueden ser privados. Sin licencia, pregunta
  a GitHub como antes. Antes de instalar, comprueba la firma del zip (el
  `.sig` que va al lado en cada release) con la clave pública de publicación
  de Caracool: una versión sin firma, o con el zip cambiado, no se instala.
  Cada plugin lo registra con `caracool_actualizador_v2( array( 'archivo',
  'slug', 'version', 'repo', 'nombre', 'resumen' ) )`; ver la cabecera del
  archivo. (La 1.0.0 usaba `caracool_actualizador()` y no comprobaba firmas;
  las dos pueden convivir en una misma web mientras se actualiza todo.)

## Cómo se usa

Cada plugin de la casa trae su propia copia de estos archivos en su carpeta
`inc/`. Antes de publicar una versión nueva de cualquiera de ellos, se
compara su copia con la de aquí y se sustituye si no coincide, para que las
copias no se vayan desincronizando con el tiempo.

Ver la cabecera de `inc/caracool-menu.php` para cómo engancharlo desde un
plugin nuevo (`require_once`, `add_submenu_page` y el filtro
`caracool_plugins`).

## Qué puede llegar aquí más adelante

Pendiente de decidir: el CSS del panel de ajustes, que hoy va copiado en cada
plugin.
