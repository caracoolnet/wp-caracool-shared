<?php
/**
 * Actualizador compartido de los plugins de Caracool.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ARCHIVO COMÚN. Es el mismo, byte a byte, en todos los plugins de la casa
 * (Vinos, Eventos, Motion, OneStep, Nueva Visión, HostPV, Toneles, Bono
 * regalo…). Fuente de verdad: github.com/caracoolnet/wp-caracool-shared,
 * carpeta inc/. Antes de publicar una versión de cualquier plugin, se
 * compara esta copia con la de ese repo y se sustituye si no coincide.
 *
 * Qué hace: cada plugin se apunta con su slug y su versión, y el
 * actualizador le pregunta a Caracool Central (updates.caracool.net) si hay
 * una más nueva, con la licencia de la web. Central le dice qué versión hay
 * y le da un enlace al zip; WordPress lo enseña y lo instala como cualquier
 * actualización. Los repositorios de GitHub son privados: la web no habla
 * con GitHub.
 *
 * Mientras la web no tenga licencia, pregunta a GitHub como antes (solo
 * sirve con los repositorios públicos). Así nada se corta durante el cambio.
 *
 * La licencia es una por web y la usan todos los plugins de la casa: va en
 * la opción `caracool_licencia` (la misma que usa Vinos) o en wp-config.php
 * como CARACOOL_LICENCIA. Se pega en el menú Caracool → Licencia.
 *
 * Cómo se usa desde el plugin:
 *
 *   require_once … 'inc/caracool-actualizador.php';
 *
 *   caracool_actualizador( array(
 *       'archivo' => __FILE__,                       // el archivo principal
 *       'slug'    => 'eventos',                      // el de Central
 *       'version' => CARACOOL_EVENTOS_VERSION,
 *       'repo'    => 'caracoolnet/wp-caracool-eventos',
 *       'nombre'  => 'Caracool Eventos',
 *       'resumen' => 'Conciertos y eventos…',        // para «Ver detalles»
 *   ) );
 * ─────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CARACOOL_MENU_SLUG' ) ) {
	define( 'CARACOOL_MENU_SLUG', 'caracool' );
}

if ( ! class_exists( 'Caracool_Actualizador' ) ) {

	final class Caracool_Actualizador {

		const VERSION = '1.0.0';
		const CENTRAL = 'https://updates.caracool.net';
		const PAGINA  = 'caracool-licencia';

		/** Los plugins apuntados: basename => datos. */
		private static $plugins = array();

		private static $enganchado = false;

		public static function registrar( $p ) {
			if ( empty( $p['archivo'] ) || empty( $p['slug'] ) || empty( $p['version'] ) ) {
				return;
			}
			$base                   = plugin_basename( $p['archivo'] );
			$p['base']              = $base;
			$p['carpeta']           = dirname( $base );
			$p['nombre']            = isset( $p['nombre'] ) ? $p['nombre'] : $p['slug'];
			$p['resumen']           = isset( $p['resumen'] ) ? $p['resumen'] : '';
			$p['repo']              = isset( $p['repo'] ) ? $p['repo'] : '';
			self::$plugins[ $base ] = $p;
			self::enganchar();
		}

		private static function enganchar() {
			if ( self::$enganchado ) {
				return;
			}
			self::$enganchado = true;
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'comprobar' ) );
			add_filter( 'plugins_api', array( __CLASS__, 'detalles' ), 20, 3 );
			add_filter( 'upgrader_pre_download', array( __CLASS__, 'enlace_fresco' ), 10, 3 );
			add_action( 'upgrader_process_complete', array( __CLASS__, 'olvidar_todo' ), 10, 0 );
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
			add_action( 'admin_post_caracool_actualizador_licencia', array( __CLASS__, 'guardar' ) );
		}

		// ── La licencia ─────────────────────────────────────────────────

		public static function licencia() {
			if ( defined( 'CARACOOL_LICENCIA' ) && CARACOOL_LICENCIA ) {
				return trim( CARACOOL_LICENCIA );
			}
			return trim( (string) get_option( 'caracool_licencia', '' ) );
		}

		/** Lo que dice la licencia (sin comprobar la firma; quien la comprueba es Central). */
		public static function licencia_datos( $texto = null ) {
			$p = explode( '.', null === $texto ? self::licencia() : trim( (string) $texto ) );
			if ( 3 !== count( $p ) || 'CB1' !== $p[0] ) {
				return null;
			}
			$json = base64_decode( strtr( $p[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $p[1] ) % 4 ) % 4 ), true );
			$d    = false === $json ? null : json_decode( $json, true );
			return ( is_array( $d ) && ! empty( $d['i'] ) ) ? $d : null;
		}

		public static function central() {
			if ( defined( 'CARACOOL_CENTRAL_URL' ) && CARACOOL_CENTRAL_URL ) {
				return untrailingslashit( CARACOOL_CENTRAL_URL );
			}
			return self::CENTRAL;
		}

		private static function dominio() {
			$h = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			return preg_replace( '/^www\./', '', $h );
		}

		// ── Preguntar ───────────────────────────────────────────────────

		private static function clave( $p ) {
			return 'caracool_act_' . md5( $p['slug'] . '|' . $p['base'] );
		}

		/**
		 * Lo último de un plugin: array( version, paquete, notas, de ) o
		 * array() si no hay nada que decir. «de» dice a quién se ha preguntado
		 * (central o github). Se guarda 6 horas; un fallo, 30 minutos.
		 */
		public static function ultima( $p, $fresca = false ) {
			$clave = self::clave( $p );
			if ( ! $fresca ) {
				$c = get_site_transient( $clave );
				if ( is_array( $c ) ) {
					return $c;
				}
			}
			$lic = self::licencia();
			$r   = '' !== $lic ? self::preguntar_central( $p, $lic ) : self::preguntar_github( $p );
			set_site_transient( $clave, $r, ! empty( $r['version'] ) ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
			return $r;
		}

		private static function preguntar_central( $p, $lic ) {
			$r = wp_remote_get(
				self::central() . '/wp-json/caracool-central/v1/plugin/' . rawurlencode( $p['slug'] ),
				array(
					'timeout' => 10,
					'headers' => array(
						'Authorization'      => 'Bearer ' . $lic,
						'X-Caracool-Dominio' => self::dominio(),
						'X-Caracool-Version' => $p['version'],
						'Accept'             => 'application/json',
					),
				)
			);
			if ( is_wp_error( $r ) ) {
				return array( 'de' => 'central', 'error' => $r->get_error_message() );
			}
			$codigo = (int) wp_remote_retrieve_response_code( $r );
			$d      = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( 200 !== $codigo || ! is_array( $d ) || empty( $d['version'] ) ) {
				$msg = is_array( $d ) && ! empty( $d['mensaje'] ) ? $d['mensaje'] : 'Central ha contestado ' . $codigo . '.';
				return array( 'de' => 'central', 'error' => $msg, 'codigo' => is_array( $d ) && isset( $d['codigo'] ) ? $d['codigo'] : (string) $codigo );
			}
			return array(
				'de'      => 'central',
				'version' => (string) $d['version'],
				'paquete' => isset( $d['paquete'] ) ? (string) $d['paquete'] : '',
				'notas'   => isset( $d['notas'] ) ? (string) $d['notas'] : '',
			);
		}

		/** Sin licencia: la release pública de GitHub, como antes. */
		private static function preguntar_github( $p ) {
			if ( '' === $p['repo'] ) {
				return array( 'de' => 'github', 'error' => 'Sin licencia y sin repositorio.' );
			}
			$r = wp_remote_get(
				'https://api.github.com/repos/' . $p['repo'] . '/releases/latest',
				array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Caracool/' . self::VERSION ) )
			);
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				return array( 'de' => 'github', 'error' => is_wp_error( $r ) ? $r->get_error_message() : 'GitHub ha contestado ' . (int) wp_remote_retrieve_response_code( $r ) . ' (si el repositorio es privado, hace falta la licencia).' );
			}
			$d = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( ! is_array( $d ) || empty( $d['tag_name'] ) ) {
				return array( 'de' => 'github', 'error' => 'GitHub no dice la versión.' );
			}
			$zip = '';
			foreach ( isset( $d['assets'] ) ? (array) $d['assets'] : array() as $a ) {
				if ( isset( $a['name'], $a['browser_download_url'] ) && '.zip' === strtolower( substr( $a['name'], -4 ) ) ) {
					$zip = $a['browser_download_url'];
					break;
				}
			}
			return array(
				'de'      => 'github',
				'version' => ltrim( (string) $d['tag_name'], 'vV' ),
				'paquete' => $zip,
				'notas'   => isset( $d['body'] ) ? (string) $d['body'] : '',
			);
		}

		// ── Lo que lee WordPress ────────────────────────────────────────

		public static function comprobar( $transient ) {
			if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
				return $transient;
			}
			foreach ( self::$plugins as $base => $p ) {
				if ( ! isset( $transient->checked[ $base ] ) ) {
					continue;
				}
				$u = self::ultima( $p );
				if ( empty( $u['version'] ) || empty( $u['paquete'] ) || version_compare( $u['version'], $p['version'], '<=' ) ) {
					if ( isset( $transient->response[ $base ] ) && ! empty( $transient->response[ $base ]->caracool ) ) {
						unset( $transient->response[ $base ] );
					}
					continue;
				}
				$item                         = new stdClass();
				$item->slug                   = $p['carpeta'];
				$item->plugin                 = $base;
				$item->new_version            = $u['version'];
				$item->url                    = 'https://caracool.net';
				$item->package                = $u['paquete'];
				$item->tested                 = get_bloginfo( 'version' );
				$item->caracool               = true;
				$transient->response[ $base ] = $item;
			}
			return $transient;
		}

		public static function detalles( $res, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
				return $res;
			}
			foreach ( self::$plugins as $p ) {
				if ( $p['carpeta'] !== $args->slug ) {
					continue;
				}
				$u              = self::ultima( $p );
				$info           = new stdClass();
				$info->name     = $p['nombre'];
				$info->slug     = $p['carpeta'];
				$info->version  = ! empty( $u['version'] ) ? $u['version'] : $p['version'];
				$info->author   = '<a href="https://caracool.net">Caracool</a>';
				$info->homepage = 'https://caracool.net';
				$info->sections = array(
					'description' => esc_html( $p['resumen'] ),
					'changelog'   => ! empty( $u['notas'] ) ? nl2br( esc_html( $u['notas'] ) ) : 'Sin notas.',
				);
				if ( ! empty( $u['paquete'] ) ) {
					$info->download_link = $u['paquete'];
				}
				return $info;
			}
			return $res;
		}

		/**
		 * El enlace de Central caduca en una hora y WordPress guarda el aviso
		 * de actualización hasta 12: justo antes de bajar, se pide uno nuevo.
		 */
		public static function enlace_fresco( $reply, $package, $upgrader = null ) {
			if ( false !== $reply || ! is_string( $package ) || false === strpos( $package, '/caracool-central/v1/paquete/' ) ) {
				return $reply;
			}
			foreach ( self::$plugins as $p ) {
				if ( false === strpos( $package, '/paquete/' . $p['slug'] . '/' ) ) {
					continue;
				}
				$u = self::ultima( $p, true );
				if ( ! empty( $u['paquete'] ) && 'central' === $u['de'] && function_exists( 'download_url' ) ) {
					return download_url( $u['paquete'], 300 );
				}
			}
			return $reply;
		}

		public static function olvidar_todo() {
			foreach ( self::$plugins as $p ) {
				delete_site_transient( self::clave( $p ) );
			}
		}

		// ── La página «Licencia» del menú Caracool ──────────────────────

		public static function menu() {
			global $submenu;
			if ( isset( $submenu[ CARACOOL_MENU_SLUG ] ) ) {
				foreach ( $submenu[ CARACOOL_MENU_SLUG ] as $s ) {
					if ( isset( $s[2] ) && self::PAGINA === $s[2] ) {
						return;
					}
				}
			}
			add_submenu_page( CARACOOL_MENU_SLUG, 'Licencia de Caracool', 'Licencia', 'manage_options', self::PAGINA, array( __CLASS__, 'pagina' ) );
		}

		public static function guardar() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Sin permisos.' );
			}
			check_admin_referer( 'caracool_actualizador_licencia' );
			$que   = isset( $_POST['que'] ) ? sanitize_key( wp_unslash( $_POST['que'] ) ) : 'guardar';
			$texto = isset( $_POST['licencia'] ) ? trim( preg_replace( '/\s+/', '', sanitize_textarea_field( wp_unslash( $_POST['licencia'] ) ) ) ) : '';
			$msg   = '';
			if ( 'guardar' === $que ) {
				if ( '' !== $texto && ! self::licencia_datos( $texto ) ) {
					$msg = 'error:Eso no parece una licencia de Caracool (empieza por CB1.).';
				} else {
					update_option( 'caracool_licencia', $texto, false );
					$msg = '' === $texto ? 'ok:Licencia quitada.' : 'ok:Licencia guardada.';
				}
			}
			self::olvidar_todo();
			delete_site_transient( 'update_plugins' );
			foreach ( self::$plugins as $p ) {
				self::ultima( $p, true );
			}
			if ( 'comprobar' === $que ) {
				$msg = 'ok:Comprobado ahora.';
			}
			wp_safe_redirect( add_query_arg( 'caracool_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . self::PAGINA ) ) );
			exit;
		}

		public static function pagina() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$msg   = isset( $_GET['caracool_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['caracool_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$lic   = self::licencia();
			$datos = self::licencia_datos();
			$conf  = defined( 'CARACOOL_LICENCIA' ) && CARACOOL_LICENCIA;
			?>
			<div class="wrap">
				<h1>Licencia de Caracool</h1>
				<?php if ( $msg ) : ?>
					<div class="notice <?php echo 0 === strpos( $msg, 'error:' ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( preg_replace( '/^(ok|error):/', '', $msg ) ); ?></p></div>
				<?php endif; ?>
				<p style="max-width:720px">Una licencia por web. La usan todos los plugins de la casa para recibir sus actualizaciones desde Caracool Central. Se pega una sola vez; si la web tiene Caracool Vinos, es la misma que lleva en Vinos → Conexión.</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Ahora</th>
						<td>
							<?php if ( $datos ) : ?>
								<strong><?php echo esc_html( $datos['c'] ); ?></strong><br>
								<?php echo esc_html( implode( ', ', isset( $datos['d'] ) ? (array) $datos['d'] : array() ) ); ?><br>
								<span style="color:#646970"><?php echo esc_html( ! empty( $datos['x'] ) ? 'Hasta el ' . $datos['x'] : 'Sin caducidad' ); ?><?php echo $conf ? ' · en wp-config.php' : ''; ?></span>
							<?php else : ?>
								Sin licencia: los plugins de la casa preguntan a GitHub, y si su repositorio es privado no recibirán actualizaciones.
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php if ( ! $conf ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="caracool_actualizador_licencia">
					<?php wp_nonce_field( 'caracool_actualizador_licencia' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="caracool-licencia">Licencia</label></th>
							<td><textarea id="caracool-licencia" name="licencia" rows="4" class="large-text code" placeholder="CB1.…"><?php echo esc_textarea( $lic ); ?></textarea>
							<p class="description">La que da Caracool para esta web. Empieza por <code>CB1.</code></p></td>
						</tr>
					</table>
					<p>
						<button type="submit" name="que" value="guardar" class="button button-primary">Guardar la licencia</button>
						<button type="submit" name="que" value="comprobar" class="button">Comprobar ahora</button>
					</p>
				</form>
				<?php endif; ?>

				<h2>Los plugins de la casa en esta web</h2>
				<table class="widefat striped" style="max-width:860px">
					<thead><tr><th>Plugin</th><th>Tiene</th><th>Última</th><th>Pregunta a</th></tr></thead>
					<tbody>
					<?php foreach ( self::$plugins as $p ) : ?>
						<?php $u = self::ultima( $p ); ?>
						<tr>
							<td><strong><?php echo esc_html( $p['nombre'] ); ?></strong></td>
							<td><?php echo esc_html( $p['version'] ); ?></td>
							<td><?php echo ! empty( $u['version'] ) ? esc_html( $u['version'] ) : '<span style="color:#b32d2e">' . esc_html( isset( $u['error'] ) ? $u['error'] : '—' ) . '</span>'; ?></td>
							<td><?php echo esc_html( isset( $u['de'] ) && 'central' === $u['de'] ? 'Caracool Central' : 'GitHub (sin licencia)' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
	}
}

if ( ! function_exists( 'caracool_actualizador' ) ) {
	/** Apunta un plugin de la casa al actualizador. */
	function caracool_actualizador( $plugin ) {
		Caracool_Actualizador::registrar( $plugin );
	}
}
