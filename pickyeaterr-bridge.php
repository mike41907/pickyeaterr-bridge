<?php
/**
 * Plugin Name: Pickyeaterr Studio Bridge
 * Description: 讓 Pickyeaterr Studio（本機部落格中控台）透過 REST API 讀寫 Rank Math 的 SEO 欄位，並在文章頁顯示 GEO／AEO 補強區塊（重點摘要、店家資訊、常見問題）與對應的結構化資料。並可接收 Studio 送來的網站樣式（CSS）輸出在網站前台。不新增後台頁面、不建立資料表、不修改文章內文。
 * Version: 2.3.0
 * Author: Pickyeaterr Studio
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PE_BRIDGE_VERSION', '2.3.0' );

/**
 * 1) REST API 可讀寫的欄位。只有「能編輯該篇文章」的帳號才能讀寫。
 *    rank_math_*：Rank Math 的 SEO 欄位
 *    pe_geo_schema：Studio 新文章的結構化資料（JSON-LD）
 *    pe_geo_summary：重點摘要（純文字）
 *    pe_geo_faq：常見問題（JSON：[{q,a}]）
 *    pe_geo_place：店家／景點資訊（JSON）
 */
add_action( 'init', function () {
	$fields = array(
		'rank_math_title'         => 'sanitize_text_field',
		'rank_math_description'   => 'sanitize_text_field',
		'rank_math_focus_keyword' => 'sanitize_text_field',
		'pe_geo_schema'           => 'pe_bridge_sanitize_json',
		'pe_geo_summary'          => 'sanitize_textarea_field',
		'pe_geo_faq'              => 'pe_bridge_sanitize_json',
		'pe_geo_place'            => 'pe_bridge_sanitize_json',
		'pe_visit_date'           => 'sanitize_text_field',
	);
	foreach ( array( 'post', 'page' ) as $post_type ) {
		foreach ( $fields as $key => $sanitize ) {
			register_post_meta( $post_type, $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_post', $object_id );
				},
			) );
		}
	}
} );

/**
 * JSON 欄位只接受合法 JSON，並重新編碼一次；所有字串值去除 HTML。
 */
function pe_bridge_sanitize_json( $value ) {
	if ( '' === trim( (string) $value ) ) {
		return '';
	}
	$decoded = json_decode( wp_unslash( $value ), true );
	if ( ! is_array( $decoded ) ) {
		return '';
	}
	array_walk_recursive( $decoded, function ( &$v ) {
		if ( is_string( $v ) ) {
			$v = wp_strip_all_tags( $v );
		}
	} );
	return wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

function pe_bridge_json_meta( $post_id, $key ) {
	$raw = get_post_meta( $post_id, $key, true );
	if ( empty( $raw ) ) {
		return null;
	}
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : null;
}

/**
 * 2) 在文章內容前後顯示 GEO／AEO 區塊。
 *    內文本身不被修改；清空欄位就會消失。結構化資料與畫面上的文字來自同一份資料，保證一致。
 */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$id      = get_the_ID();
	$summary = trim( (string) get_post_meta( $id, 'pe_geo_summary', true ) );
	$place   = pe_bridge_json_meta( $id, 'pe_geo_place' );
	$faq     = pe_bridge_json_meta( $id, 'pe_geo_faq' );

	$before = '';
	if ( '' !== $summary ) {
		$before = '<div class="pe-geo pe-geo-summary"><p class="pe-geo-label">重點摘要</p><p>' . nl2br( esc_html( $summary ) ) . '</p></div>';
	}

	$after = '';
	if ( $place ) {
		$labels = array(
			'name'      => '名稱',
			'address'   => '地址',
			'hours'     => '營業時間',
			'price'     => '價位',
			'phone'     => '電話／訂位',
			'transport' => '交通',
			'visitDate' => '造訪日期',
		);
		$rows = '';
		foreach ( $labels as $k => $label ) {
			if ( ! empty( $place[ $k ] ) ) {
				$rows .= '<li><strong>' . esc_html( $label ) . '：</strong>' . esc_html( $place[ $k ] ) . '</li>';
			}
		}
		if ( $rows ) {
			$title  = ( isset( $place['type'] ) && 'food' !== $place['type'] ) ? '景點資訊' : '店家資訊';
			$after .= '<div class="pe-geo pe-geo-place"><h2>' . esc_html( $title ) . '</h2><ul>' . $rows . '</ul></div>';
		}
	}
	if ( $faq ) {
		$items = '';
		foreach ( $faq as $f ) {
			if ( empty( $f['q'] ) || empty( $f['a'] ) ) {
				continue;
			}
			$items .= '<h3>' . esc_html( $f['q'] ) . '</h3><p>' . esc_html( $f['a'] ) . '</p>';
		}
		if ( $items ) {
			$after .= '<div class="pe-geo pe-geo-faq"><h2>常見問題</h2>' . $items . '</div>';
		}
	}
	return $before . $content . $after;
}, 20 );

add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}
	$id = get_the_ID();
	if ( ! get_post_meta( $id, 'pe_geo_summary', true ) && ! get_post_meta( $id, 'pe_geo_place', true ) && ! get_post_meta( $id, 'pe_geo_faq', true ) ) {
		return;
	}
	// 只用主題字型與顏色，避免和佈景主題衝突；想調整可在「外觀 → 自訂 → 額外 CSS」覆寫 .pe-geo
	echo "<style id=\"pe-geo-css\">.pe-geo{border:1px solid rgba(0,0,0,.12);border-radius:10px;padding:16px 20px;margin:24px 0}.pe-geo-summary{background:rgba(0,0,0,.03)}.pe-geo-label{font-size:.85em;font-weight:600;letter-spacing:.08em;opacity:.7;margin:0 0 6px}.pe-geo-summary p:last-child{margin:0}.pe-geo h2{margin-top:0}.pe-geo-place ul{margin:0;padding-left:1.2em}.pe-geo-faq h3{font-size:1.05em;margin:16px 0 4px}</style>\n";
}, 20 );

/**
 * 3) 結構化資料（JSON-LD）。
 */
add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}
	$id    = get_the_ID();
	$graph = array();

	// Studio 新文章寫入的完整結構化資料
	$schema = pe_bridge_json_meta( $id, 'pe_geo_schema' );
	if ( $schema && isset( $schema['@graph'] ) && is_array( $schema['@graph'] ) ) {
		$graph = $schema['@graph'];
	}

	// GEO／AEO 補強的店家資訊與常見問題（與畫面上顯示的文字同源）
	$place = pe_bridge_json_meta( $id, 'pe_geo_place' );
	if ( $place && ( ! empty( $place['name'] ) || ! empty( $place['address'] ) ) ) {
		$types = array( 'food' => 'Restaurant', 'place' => 'TouristAttraction', 'stay' => 'LodgingBusiness' );
		$node  = array( '@type' => isset( $types[ $place['type'] ?? '' ] ) ? $types[ $place['type'] ] : 'LocalBusiness' );
		$node['name'] = ! empty( $place['name'] ) ? $place['name'] : get_the_title( $id );
		if ( ! empty( $place['address'] ) ) {
			$node['address'] = $place['address'];
		}
		if ( ! empty( $place['phone'] ) && preg_match( '/\d{6,}/', preg_replace( '/[\s\-()]/', '', $place['phone'] ) ) ) {
			$node['telephone'] = $place['phone'];
		}
		// 只輸出畫面上也看得到的資訊：有寫價位文字時才放 priceRange
		if ( ! empty( $place['priceRange'] ) && ! empty( $place['price'] ) ) {
			$node['priceRange'] = $place['priceRange'];
		}
		if ( ! empty( $place['openingHours'] ) && is_array( $place['openingHours'] ) ) {
			$node['openingHoursSpecification'] = array();
			foreach ( $place['openingHours'] as $h ) {
				if ( empty( $h['days'] ) || empty( $h['opens'] ) || empty( $h['closes'] ) ) {
					continue;
				}
				$node['openingHoursSpecification'][] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => $h['days'],
					'opens'     => $h['opens'],
					'closes'    => $h['closes'],
				);
			}
		}
		$graph = array_values( array_filter( $graph, function ( $n ) {
			return ! in_array( $n['@type'] ?? '', array( 'Restaurant', 'TouristAttraction', 'LodgingBusiness', 'LocalBusiness' ), true );
		} ) );
		$graph[] = $node;
	}
	$faq = pe_bridge_json_meta( $id, 'pe_geo_faq' );
	if ( $faq ) {
		$entities = array();
		foreach ( $faq as $f ) {
			if ( ! empty( $f['q'] ) && ! empty( $f['a'] ) ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $f['q'],
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f['a'] ),
				);
			}
		}
		if ( $entities ) {
			$graph = array_values( array_filter( $graph, function ( $n ) {
				return 'FAQPage' !== ( $n['@type'] ?? '' );
			} ) );
			$graph[] = array( '@type' => 'FAQPage', 'mainEntity' => $entities );
		}
	}
	if ( ! $graph ) {
		return;
	}
	echo "\n<script type=\"application/ld+json\" class=\"pe-geo-schema\">"
		. wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
		. "</script>\n";
}, 30 );

/**
 * 4) 健康檢查端點：GET /wp-json/pe-bridge/v1/status
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/status', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function () {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all     = get_plugins();
			$active  = (array) get_option( 'active_plugins', array() );
			$plugins = array();
			foreach ( $active as $file ) {
				if ( isset( $all[ $file ] ) ) {
					$plugins[] = array(
						'name'    => $all[ $file ]['Name'],
						'version' => $all[ $file ]['Version'],
					);
				}
			}
			$theme = wp_get_theme();
			return array(
				'bridge_version'      => PE_BRIDGE_VERSION,
				'wp_version'          => get_bloginfo( 'version' ),
				'php_version'         => PHP_VERSION,
				'rank_math'           => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
				'theme'               => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
				'active_plugins'      => $plugins,
				'object_cache'        => wp_using_ext_object_cache(),
				'permalink_structure' => get_option( 'permalink_structure' ),
				'site_language'       => get_bloginfo( 'language' ),
				'blog_public'         => (bool) get_option( 'blog_public' ),
			);
		},
	) );
} );

/**
 * 5) 網站樣式（CSS）：由 Studio 送來，存在一個 option，輸出在前台 <head>。
 *    GET  /wp-json/pe-bridge/v1/css   讀取目前的 CSS
 *    POST /wp-json/pe-bridge/v1/css   { css, name }；css 為空字串＝停用
 *    只有能編輯他人文章的帳號（編輯以上）可以讀寫。內容只允許純 CSS：
 *    含 <、@import、javascript:、expression()、外部網址的 url() 一律拒絕。
 */
function pe_bridge_css_check( $css ) {
	if ( strlen( $css ) > 60000 ) {
		return '樣式太長（上限 60000 字元）。';
	}
	if ( false !== strpos( $css, '<' ) ) {
		return '樣式裡不能有 < 符號。';
	}
	if ( preg_match( '/@import|javascript:|expression\s*\(|behavior\s*:|-moz-binding/i', $css ) ) {
		return '樣式裡有不允許的語法（@import、javascript:、expression 等）。';
	}
	if ( preg_match( '/url\s*\(\s*[\'"]?\s*(https?:)?\/\//i', $css ) ) {
		return '樣式裡不能引用外部網址的 url()；圖片請改用 data: 或不用。';
	}
	return '';
}


/**
 * 0) 清除網站快取：樣式、首頁、相關文章設定改變後，快取外掛留著的舊 HTML 不會有新樣式，
 *    所以每次同步完呼叫一次。只清已安裝的常見快取外掛，回傳清掉了哪些。
 *    Cloudflare 等外部 CDN 的快取清不到，要在它們那邊清除。
 */
function pe_bridge_purge_caches() {
	$done = array();
	if ( has_action( 'epc_purge' ) || class_exists( 'Endurance_Page_Cache' ) || class_exists( 'EPC_Base' ) ) {
		do_action( 'epc_purge' );
		$done[] = 'Bluehost 頁面快取';
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$done[] = 'WP Rocket';
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
		$done[] = 'LiteSpeed Cache';
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$done[] = 'W3 Total Cache';
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
		$done[] = 'WP Super Cache';
	}
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
		$done[] = 'SG Optimizer';
	}
	if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
		autoptimizeCache::clearall();
		$done[] = 'Autoptimize';
	}
	// NitroPack（v1.9）：它會另外保存最佳化過的頁面，不清的話舊文章會一直是舊樣式
	if ( function_exists( 'nitropack_sdk_purge' ) ) {
		try {
			nitropack_sdk_purge( null, null, 'Pickyeaterr Studio 更新樣式' );
			$done[] = 'NitroPack';
		} catch ( \Throwable $e ) {
			$done[] = 'NitroPack（清除失敗：' . $e->getMessage() . '）';
		}
	} elseif ( function_exists( 'nitropack_purge' ) ) {
		try {
			nitropack_purge( null, null, 'Pickyeaterr Studio 更新樣式' );
			$done[] = 'NitroPack';
		} catch ( \Throwable $e ) {
			$done[] = 'NitroPack（清除失敗：' . $e->getMessage() . '）';
		}
	}
	if ( has_action( 'cloudflare_purge_everything' ) ) {
		do_action( 'cloudflare_purge_everything' );
		$done[] = 'Cloudflare 外掛';
	}
	wp_cache_flush();
	return $done;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/purge', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_others_posts' );
		},
		'callback'            => function () {
			return array( 'ok' => true, 'purged' => pe_bridge_purge_caches() );
		},
	) );
} );


/**
 * 舊樣式管理（v1.6）：讀取、備份後清除、還原「外觀 → 自訂 → 額外 CSS」。
 * 舊的額外 CSS 常用 !important 或較晚載入，會蓋掉 Studio 的新樣式。
 */
add_action( 'rest_api_init', function () {
	// 清除／還原「額外 CSS」是佈景主題設定，維持 WordPress 原本的規則：只有管理員能改
	$perm = function () {
		return current_user_can( 'edit_theme_options' ) || current_user_can( 'manage_options' );
	};
	register_rest_route( 'pe-bridge/v1', '/oldcss', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' ); // 只讀：編輯帳號也能看（診斷用）
			},
			'callback'            => function () {
				$css = (string) wp_get_custom_css();
				$bak = (string) get_option( 'pe_old_css_backup', '' );
				return array(
					'ok'      => true,
					'bytes'   => strlen( $css ),
					'css'     => mb_substr( $css, 0, 20000 ),
					'backup'  => strlen( $bak ),
					'theme'   => get_stylesheet(),
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) {
				$act = (string) $req->get_param( 'action' );
				if ( 'clear' === $act ) {
					$css = (string) wp_get_custom_css();
					if ( '' !== trim( $css ) ) {
						update_option( 'pe_old_css_backup', $css, false );
					}
					$r = wp_update_custom_css_post( '' );
				} elseif ( 'restore' === $act ) {
					$bak = (string) get_option( 'pe_old_css_backup', '' );
					if ( '' === $bak ) {
						return new WP_Error( 'pe_no_backup', '沒有可還原的備份', array( 'status' => 404 ) );
					}
					$r = wp_update_custom_css_post( $bak );
				} else {
					return new WP_Error( 'pe_bad_action', '不支援的動作', array( 'status' => 400 ) );
				}
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				return array( 'ok' => true, 'bytes' => strlen( (string) wp_get_custom_css() ), 'backup' => strlen( (string) get_option( 'pe_old_css_backup', '' ) ), 'purged' => pe_bridge_purge_caches() );
			},
		),
	) );
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/css', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function () {
				$meta = get_option( 'pe_studio_css_meta', array() );
				return array(
					'css'        => (string) get_option( 'pe_studio_css', '' ),
					'name'       => isset( $meta['name'] ) ? $meta['name'] : '',
					'updated_at' => isset( $meta['updated_at'] ) ? $meta['updated_at'] : '',
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function ( WP_REST_Request $req ) {
				$css = str_replace( "\r\n", "\n", (string) $req->get_param( 'css' ) );
				$css = trim( $css );
				$bad = pe_bridge_css_check( $css );
				if ( $bad ) {
					return new WP_Error( 'pe_css_rejected', $bad, array( 'status' => 400 ) );
				}
				update_option( 'pe_studio_css', $css, true );
				update_option( 'pe_studio_css_meta', array(
					'name'       => sanitize_text_field( (string) $req->get_param( 'name' ) ),
					'updated_at' => gmdate( 'c' ),
				), false );
				return array( 'ok' => true, 'bytes' => strlen( $css ), 'purged' => pe_bridge_purge_caches() );
			},
		),
	) );
} );

/**
 * 樣式預覽（v1.7）：Studio 先把候選樣式存成預覽，只有帶著正確 pe_preview 代碼的網址才看得到，
 * 其他訪客照常看到目前的樣式。預覽 2 小時後失效。
 */
function pe_bridge_preview_css() {
	if ( empty( $_GET['pe_preview'] ) ) {
		return null;
	}
	$p = get_option( 'pe_studio_css_preview', array() );
	if ( ! is_array( $p ) || empty( $p['token'] ) || empty( $p['css'] ) ) {
		return null;
	}
	if ( ! hash_equals( (string) $p['token'], (string) wp_unslash( $_GET['pe_preview'] ) ) ) {
		return null;
	}
	if ( (int) $p['expires'] < time() ) {
		return null;
	}
	return (string) $p['css'];
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/css-preview', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_others_posts' );
		},
		'callback'            => function ( $req ) {
			$css = (string) $req->get_param( 'css' );
			if ( pe_bridge_css_check( $css ) ) {
				return new WP_Error( 'pe_css_rejected', '樣式裡有不允許的內容。', array( 'status' => 400 ) );
			}
			$token = wp_generate_password( 20, false, false );
			update_option( 'pe_studio_css_preview', array( 'css' => $css, 'token' => $token, 'expires' => time() + 2 * HOUR_IN_SECONDS ), false );
			return array( 'ok' => true, 'token' => $token, 'expires_in' => 2 * HOUR_IN_SECONDS );
		},
	) );
} );

// 帶預覽代碼的頁面不要被快取，避免預覽樣式被存給一般訪客
add_action( 'init', function () {
	if ( ! empty( $_GET['pe_preview'] ) ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}
} );

add_action( 'wp_head', function () {
	$preview = pe_bridge_preview_css();
	$css     = null !== $preview ? $preview : (string) get_option( 'pe_studio_css', '' );
	if ( '' === $css || pe_bridge_css_check( $css ) ) {
		return;
	}
	// 先清掉舊樣式常見的標題底色與陰影（同樣強度、排在 Studio 樣式前面，Studio 樣式要用的話會再設定回來）
	$reset = 'body.single-post .entry-content h2,body.single-post .entry-content h3{background:none;box-shadow:none}';
	// 版本標記：CSS 最佳化外掛（例如 NitroPack）可能把樣式合併、拿掉 id，Studio 抽查時改看這個標記判斷頁面是不是最新的
	if ( null === $preview ) {
		echo '<meta name="pe-studio-css" content="' . esc_attr( md5( $css ) ) . '">' . "\n";
	}
	echo '<style id="pe-studio-css"' . ( null !== $preview ? ' data-preview="1"' : '' ) . '>' . $reset . $css . "</style>\n";
}, 102 ); // v1.8：排在「額外 CSS」（101）之後，避免舊的額外 CSS 蓋掉 Studio 樣式

/**
 * 6) 聯盟推薦卡片（.pe-card）的基本外觀：文章裡有卡片時才輸出，只用主題字型與顏色。
 *    「網站樣式」送來的 CSS（優先權 99）會蓋過這一份。
 */
add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}
	$content = (string) get_post_field( 'post_content', get_the_ID() );
	if ( false === strpos( $content, 'pe-card' ) ) {
		return;
	}
	echo "<style id=\"pe-card-base\">.pe-card{border:1px solid rgba(0,0,0,.12);border-radius:10px;padding:16px 20px;margin:24px 0}.pe-card-tag{font-size:.8em;letter-spacing:.08em;opacity:.7}.pe-card-title{font-weight:700;font-size:1.1em;margin:4px 0}.pe-card-why{margin:6px 0 12px}.pe-card a.pe-card-btn{display:inline-block;padding:.5em 1.2em;border:1px solid currentColor;border-radius:6px;text-decoration:none}.pe-disclosure{font-size:.85em;opacity:.7}</style>\n";
}, 98 );

/**
 * 7) 網站功能設定（Studio 同步）：文末相關文章、造訪日期。
 *    GET  /wp-json/pe-bridge/v1/site
 *    POST /wp-json/pe-bridge/v1/site  { settings: {...}, related: { "文章ID": [ID, ID, ID] } }
 */
function pe_bridge_site_settings() {
	$s = get_option( 'pe_site_settings', array() );
	return array(
		'related_enabled' => ! empty( $s['related_enabled'] ),
		'related_heading' => isset( $s['related_heading'] ) && '' !== $s['related_heading'] ? $s['related_heading'] : '延伸閱讀',
		'related_count'   => isset( $s['related_count'] ) ? max( 2, min( 6, (int) $s['related_count'] ) ) : 3,
		'visit_enabled'   => ! empty( $s['visit_enabled'] ),
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/site', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function () {
				$map = get_option( 'pe_related_map', array() );
				return array(
					'settings'      => pe_bridge_site_settings(),
					'related_posts' => is_array( $map ) ? count( $map ) : 0,
					'related_at'    => (string) get_option( 'pe_related_at', '' ),
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function ( WP_REST_Request $req ) {
				$in = $req->get_param( 'settings' );
				if ( is_array( $in ) ) {
					$cur = get_option( 'pe_site_settings', array() );
					foreach ( array( 'related_enabled', 'visit_enabled' ) as $k ) {
						if ( isset( $in[ $k ] ) ) {
							$cur[ $k ] = (bool) $in[ $k ];
						}
					}
					if ( isset( $in['related_heading'] ) ) {
						$cur['related_heading'] = mb_substr( sanitize_text_field( (string) $in['related_heading'] ), 0, 30 );
					}
					if ( isset( $in['related_count'] ) ) {
						$cur['related_count'] = max( 2, min( 6, (int) $in['related_count'] ) );
					}
					update_option( 'pe_site_settings', $cur, true );
				}
				$rel = $req->get_param( 'related' );
				if ( is_array( $rel ) ) {
					$clean = array();
					foreach ( $rel as $pid => $ids ) {
						$pid = (int) $pid;
						if ( $pid <= 0 || ! is_array( $ids ) ) {
							continue;
						}
						$clean[ $pid ] = array_slice( array_values( array_filter( array_map( 'intval', $ids ), function ( $x ) use ( $pid ) {
							return $x > 0 && $x !== $pid;
						} ) ), 0, 6 );
						if ( count( $clean ) >= 3000 ) {
							break;
						}
					}
					update_option( 'pe_related_map', $clean, false );
					update_option( 'pe_related_at', gmdate( 'c' ), false );
				}
				return array( 'ok' => true, 'settings' => pe_bridge_site_settings(), 'purged' => pe_bridge_purge_caches() );
			},
		),
	) );
} );

// 文末相關文章：Studio 算好的優先；沒有的話用同分類最新文章補
function pe_bridge_related_ids( $post_id, $count ) {
	$map = get_option( 'pe_related_map', array() );
	$ids = ( is_array( $map ) && isset( $map[ $post_id ] ) ) ? $map[ $post_id ] : array();
	$ids = array_values( array_filter( $ids, function ( $id ) {
		return 'publish' === get_post_status( $id );
	} ) );
	if ( count( $ids ) < $count ) {
		$cats = wp_get_post_categories( $post_id );
		if ( $cats ) {
			$more = get_posts( array(
				'post_type'           => 'post',
				'posts_per_page'      => $count,
				'category__in'        => $cats,
				'post__not_in'        => array_merge( array( $post_id ), $ids ),
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			) );
			$ids = array_merge( $ids, $more );
		}
	}
	return array_slice( array_unique( $ids ), 0, $count );
}

add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$s  = pe_bridge_site_settings();
	$id = get_the_ID();

	if ( $s['visit_enabled'] ) {
		$visit = trim( (string) get_post_meta( $id, 'pe_visit_date', true ) );
		if ( '' === $visit ) {
			$place = pe_bridge_json_meta( $id, 'pe_geo_place' );
			$visit = ( $place && ! empty( $place['visitDate'] ) ) ? (string) $place['visitDate'] : '';
		}
		if ( '' !== $visit ) {
			$content = '<p class="pe-visit-date"><span class="pe-visit-label">造訪日期</span>' . esc_html( $visit ) . '<span class="pe-visit-note">・價格與營業時間以當時為準</span></p>' . $content;
		}
	}

	if ( $s['related_enabled'] ) {
		$ids = pe_bridge_related_ids( $id, $s['related_count'] );
		if ( $ids ) {
			$items = '';
			foreach ( $ids as $rid ) {
				$img    = get_the_post_thumbnail( $rid, 'medium_large', array( 'loading' => 'lazy', 'class' => 'pe-related-thumb', 'alt' => '', 'sizes' => '(max-width: 560px) 100vw, 360px' ) );
				$items .= '<a class="pe-related-item" href="' . esc_url( get_permalink( $rid ) ) . '"><span class="pe-related-img">' . $img . '</span><span class="pe-related-name">' . esc_html( get_the_title( $rid ) ) . '</span></a>';
			}
			$content .= '<section class="pe-related"><p class="pe-related-title">' . esc_html( $s['related_heading'] ) . '</p><div class="pe-related-grid">' . $items . '</div></section>';
		}
	}
	return $content;
}, 30 );

add_action( 'wp_head', function () {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$s = pe_bridge_site_settings();
	if ( ! $s['related_enabled'] && ! $s['visit_enabled'] ) {
		return;
	}
	// 基本外觀；「網站樣式」送來的 CSS（優先權 99）會蓋過這一份
	echo "<style id=\"pe-site-base\">.pe-visit-date{font-size:.85em;opacity:.8;margin:0 0 1.2em}.pe-visit-label{font-weight:700;margin-right:.5em}.pe-related{margin:3em 0 1em;padding-top:1.5em;border-top:1px solid rgba(0,0,0,.1)}.pe-related-title{font-weight:700;font-size:1.1em;margin:0 0 .8em}.pe-related-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}.pe-related-item{display:block;text-decoration:none;color:inherit}.pe-related-img{display:block;aspect-ratio:4/3;overflow:hidden;border-radius:8px;background:rgba(0,0,0,.06)}.pe-related-img img{width:100%;height:100%;object-fit:cover;display:block}.pe-related-name{display:block;margin-top:.5em;font-size:.95em;line-height:1.5}</style>\n";
}, 97 );

/**
 * 8) 首頁（Studio 同步，v1.4）：短代碼 [pe_home]，內容、顏色、地區、熱門文章都由 Studio 設定，
 *    最新文章由 WordPress 自己即時查詢。
 *    GET  /wp-json/pe-bridge/v1/home
 *    POST /wp-json/pe-bridge/v1/home  { config: {...}, popular: [文章ID, ...] }
 */
function pe_bridge_home_defaults() {
	return array(
		'title'         => '你要去哪裡？',
		'intro'         => '選一個地方，景點、餐廳、交通、行程都在同一頁。',
		'placeholder'   => '搜尋地名、景點、餐廳',
		'show_search'   => true,
		'latest_count'  => 5,
		'popular_count' => 5,
		'hubs_heading'  => '旅遊總整理',
		'regions'       => array(),
		'hubs'          => array(),
		'palette'       => array(
			'bg'         => '#F6F7F5',
			'surface'    => '#FFFFFF',
			'ink'        => '#17211E',
			'muted'      => '#46534E',
			'line'       => '#D5DBD7',
			'accent'     => '#1F6F5C',
			'accent_ink' => '#8FD0BC',
			'dark'       => '#17211E',
		),
	);
}

function pe_bridge_home_config() {
	$def = pe_bridge_home_defaults();
	$cur = get_option( 'pe_home_config', array() );
	if ( ! is_array( $cur ) ) {
		$cur = array();
	}
	$out = array_merge( $def, array_intersect_key( $cur, $def ) );
	$out['palette'] = array_merge( $def['palette'], isset( $cur['palette'] ) && is_array( $cur['palette'] ) ? array_intersect_key( $cur['palette'], $def['palette'] ) : array() );
	return $out;
}

function pe_bridge_home_sanitize( $in ) {
	$def = pe_bridge_home_defaults();
	$out = pe_bridge_home_config();
	if ( isset( $in['title'] ) ) {
		$out['title'] = mb_substr( sanitize_text_field( (string) $in['title'] ), 0, 60 );
	}
	if ( isset( $in['intro'] ) ) {
		$out['intro'] = mb_substr( sanitize_text_field( (string) $in['intro'] ), 0, 200 );
	}
	if ( isset( $in['placeholder'] ) ) {
		$out['placeholder'] = mb_substr( sanitize_text_field( (string) $in['placeholder'] ), 0, 30 );
	}
	if ( isset( $in['hubs_heading'] ) ) {
		$out['hubs_heading'] = mb_substr( sanitize_text_field( (string) $in['hubs_heading'] ), 0, 20 );
	}
	if ( isset( $in['show_search'] ) ) {
		$out['show_search'] = (bool) $in['show_search'];
	}
	foreach ( array( 'latest_count', 'popular_count' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$out[ $k ] = max( 3, min( 8, (int) $in[ $k ] ) );
		}
	}
	if ( isset( $in['regions'] ) && is_array( $in['regions'] ) ) {
		$out['regions'] = array();
		foreach ( array_slice( $in['regions'], 0, 9 ) as $r ) {
			$cid = isset( $r['cat_id'] ) ? (int) $r['cat_id'] : 0;
			if ( $cid > 0 && is_array( $r ) ) {
				$out['regions'][] = array( 'name' => mb_substr( sanitize_text_field( (string) ( $r['name'] ?? '' ) ), 0, 20 ), 'cat_id' => $cid );
			}
		}
	}
	if ( isset( $in['hubs'] ) && is_array( $in['hubs'] ) ) {
		$out['hubs'] = array();
		foreach ( array_slice( $in['hubs'], 0, 6 ) as $h ) {
			$pid = isset( $h['post_id'] ) ? (int) $h['post_id'] : 0;
			if ( $pid > 0 && is_array( $h ) ) {
				$out['hubs'][] = array( 'name' => mb_substr( sanitize_text_field( (string) ( $h['name'] ?? '' ) ), 0, 40 ), 'post_id' => $pid );
			}
		}
	}
	if ( isset( $in['palette'] ) && is_array( $in['palette'] ) ) {
		foreach ( $def['palette'] as $k => $v ) {
			if ( isset( $in['palette'][ $k ] ) ) {
				$hex = sanitize_hex_color( (string) $in['palette'][ $k ] );
				if ( $hex ) {
					$out['palette'][ $k ] = $hex;
				}
			}
		}
	}
	return $out;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/home', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function () {
				$pop = get_option( 'pe_home_popular', array() );
				return array(
					'config'     => pe_bridge_home_config(),
					'popular'    => is_array( $pop ) ? array_values( array_map( 'intval', $pop ) ) : array(),
					'popular_at' => (string) get_option( 'pe_home_popular_at', '' ),
					'version'    => '2.3.0',
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function ( WP_REST_Request $req ) {
				$cfg = $req->get_param( 'config' );
				if ( is_array( $cfg ) ) {
					update_option( 'pe_home_config', pe_bridge_home_sanitize( $cfg ), true );
				}
				$pop = $req->get_param( 'popular' );
				if ( is_array( $pop ) ) {
					update_option( 'pe_home_popular', array_slice( array_values( array_unique( array_filter( array_map( 'intval', $pop ), function ( $x ) {
						return $x > 0;
					} ) ) ), 0, 20 ), false );
					update_option( 'pe_home_popular_at', gmdate( 'c' ), false );
				}
				return array( 'ok' => true, 'purged' => pe_bridge_purge_caches() );
			},
		),
	) );
} );

function pe_bridge_home_post_row( $pid ) {
	// 首頁列表縮圖只有 140×96（手機 104×78）：告訴瀏覽器實際寬度，手機才會挑 300px 的小圖，不會抓 768px 大圖
	$thumb = get_the_post_thumbnail( $pid, 'medium_large', array( 'loading' => 'lazy', 'alt' => '', 'sizes' => '(max-width: 560px) 104px, 140px' ) );
	$cats  = get_the_category( $pid );
	return array(
		'url'   => get_permalink( $pid ),
		'title' => get_the_title( $pid ),
		'thumb' => $thumb,
		'cat'   => $cats ? $cats[0]->name : '',
		'date'  => get_the_date( 'Y.m.d', $pid ),
	);
}

add_shortcode( 'pe_home', function () {
	$c = pe_bridge_home_config();
	$h = '<div class="pe-home">';

	$h .= '<section class="pe-h-hero"><h1 class="pe-h-title">' . esc_html( $c['title'] ) . '</h1>';
	if ( '' !== $c['intro'] ) {
		$h .= '<p class="pe-h-intro">' . esc_html( $c['intro'] ) . '</p>';
	}
	if ( $c['show_search'] ) {
		$h .= '<form class="pe-h-search" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '"><label class="screen-reader-text" for="pe-h-s">搜尋</label><input id="pe-h-s" type="search" name="s" placeholder="' . esc_attr( $c['placeholder'] ) . '"><button type="submit">搜尋</button></form>';
	}
	$h .= '</section>';

	if ( $c['regions'] ) {
		$h .= '<section class="pe-h-regions">';
		foreach ( $c['regions'] as $r ) {
			$term = get_term( (int) $r['cat_id'], 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$ids = get_posts( array(
				'post_type'           => 'post',
				'posts_per_page'      => 1,
				'cat'                 => $term->term_id,
				'meta_key'            => '_thumbnail_id',
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			) );
			$img = $ids ? get_the_post_thumbnail( $ids[0], 'medium_large', array( 'loading' => 'lazy', 'alt' => '', 'sizes' => '(max-width: 900px) 50vw, 360px' ) ) : '';
			$nm  = '' !== $r['name'] ? $r['name'] : $term->name;
			$h  .= '<a class="pe-h-region" href="' . esc_url( get_category_link( $term ) ) . '"><span class="pe-h-region-img">' . $img . '</span><span class="pe-h-region-meta"><span class="pe-h-region-name">' . esc_html( $nm ) . '</span><span class="pe-h-region-count">' . (int) $term->count . ' 篇</span></span></a>';
		}
		$h .= '</section>';
	}

	// 最新文章
	$latest = get_posts( array(
		'post_type'           => 'post',
		'posts_per_page'      => $c['latest_count'],
		'fields'              => 'ids',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );
	$h .= '<section class="pe-h-main"><div class="pe-h-latest"><div class="pe-h-head"><h2>最新文章</h2></div>';
	foreach ( $latest as $pid ) {
		$p  = pe_bridge_home_post_row( $pid );
		$h .= '<a class="pe-h-row" href="' . esc_url( $p['url'] ) . '"><span class="pe-h-row-img">' . $p['thumb'] . '</span><span class="pe-h-row-body"><span class="pe-h-row-tag">' . esc_html( $p['cat'] ) . '<span class="pe-h-row-date">' . esc_html( $p['date'] ) . '</span></span><span class="pe-h-row-title">' . esc_html( $p['title'] ) . '</span></span></a>';
	}
	$h .= '</div><aside class="pe-h-side">';

	// 熱門：Studio 依 GA4 近 30 天瀏覽數排好；不足的用最新文章補
	$pop = get_option( 'pe_home_popular', array() );
	$pop = is_array( $pop ) ? array_values( array_filter( $pop, function ( $id ) {
		return 'publish' === get_post_status( $id );
	} ) ) : array();
	if ( count( $pop ) < $c['popular_count'] ) {
		$more = get_posts( array(
			'post_type'           => 'post',
			'posts_per_page'      => $c['popular_count'],
			'post__not_in'        => $pop,
			'fields'              => 'ids',
			'orderby'             => 'comment_count date',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		) );
		$pop  = array_merge( $pop, $more );
	}
	$pop = array_slice( $pop, 0, $c['popular_count'] );
	if ( $pop ) {
		$h .= '<div class="pe-h-head"><h2>熱門</h2></div><ol class="pe-h-pop">';
		foreach ( $pop as $i => $pid ) {
			$h .= '<li><a href="' . esc_url( get_permalink( $pid ) ) . '"><span class="pe-h-pop-n">' . ( $i + 1 ) . '</span><span class="pe-h-pop-t">' . esc_html( get_the_title( $pid ) ) . '</span></a></li>';
		}
		$h .= '</ol>';
	}

	$hubs = array();
	foreach ( $c['hubs'] as $hb ) {
		if ( 'publish' === get_post_status( $hb['post_id'] ) ) {
			$hubs[] = $hb;
		}
	}
	if ( $hubs ) {
		$h .= '<div class="pe-h-hubs"><p class="pe-h-hubs-title">' . esc_html( $c['hubs_heading'] ) . '</p>';
		foreach ( $hubs as $hb ) {
			$nm = '' !== $hb['name'] ? $hb['name'] : get_the_title( $hb['post_id'] );
			$h .= '<a href="' . esc_url( get_permalink( $hb['post_id'] ) ) . '"><span>' . esc_html( $nm ) . '</span><span aria-hidden="true">→</span></a>';
		}
		$h .= '</div>';
	}
	$h .= '</aside></section></div>';
	return $h;
} );

function pe_bridge_is_home_page() {
	if ( ! is_singular( 'page' ) ) {
		return false;
	}
	return has_shortcode( (string) get_post_field( 'post_content', get_the_ID() ), 'pe_home' );
}

add_filter( 'body_class', function ( $classes ) {
	if ( pe_bridge_is_home_page() ) {
		$classes[] = 'pe-home-page';
	}
	return $classes;
} );

add_action( 'wp_head', function () {
	if ( ! pe_bridge_is_home_page() ) {
		return;
	}
	$p   = pe_bridge_home_config()['palette'];
	$css = '.pe-home{--pe-bg:' . $p['bg'] . ';--pe-surface:' . $p['surface'] . ';--pe-ink:' . $p['ink'] . ';--pe-muted:' . $p['muted'] . ';--pe-line:' . $p['line'] . ';--pe-accent:' . $p['accent'] . ';--pe-accent-ink:' . $p['accent_ink'] . ';--pe-dark:' . $p['dark'] . '}';
	$css .= '.pe-home-page .entry-header,.pe-home-page .page-header{display:none}.pe-home-page #secondary{display:none}.pe-home-page #primary{width:100%!important;max-width:none!important;margin:0!important;padding:0!important}.pe-home-page .site-content .ast-container{display:block;max-width:100%;padding:0}.pe-home-page .entry-content>.pe-home{margin:0}.pe-home-page .ast-article-single,.pe-home-page .ast-article-post{padding:0!important;background:transparent!important}';
	$css .= '.pe-home{background:var(--pe-bg);color:var(--pe-ink);padding:8px 0 56px}.pe-home>section{max-width:1200px;margin-left:auto;margin-right:auto;padding-left:24px;padding-right:24px;box-sizing:border-box}.pe-home a{color:inherit;text-decoration:none}.pe-home a:hover{color:var(--pe-accent)}';
	$css .= '.pe-h-hero{padding-top:40px;padding-bottom:32px}.pe-h-title{margin:0 0 12px;font-size:clamp(30px,5vw,44px);line-height:1.3;font-weight:900;color:var(--pe-ink)}.pe-h-intro{margin:0 0 22px;font-size:16px;line-height:1.8;color:var(--pe-muted);max-width:560px}';
	$css .= '.pe-h-search{display:flex;max-width:520px;border:1px solid var(--pe-line);background:var(--pe-surface)}.pe-h-search input[type=search]{flex:1;min-width:0;border:0;padding:15px 18px;font-size:16px;background:transparent;color:var(--pe-ink);outline-offset:-2px}.pe-h-search button{border:0;background:var(--pe-accent);color:#fff;padding:0 22px;font-size:15px;cursor:pointer;min-height:48px}';
	$css .= '.pe-h-regions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}.pe-h-region{display:block;background:var(--pe-surface);border:1px solid var(--pe-line)}.pe-h-region-img{display:block;height:150px;background:var(--pe-line);overflow:hidden}.pe-h-region-img img{width:100%;height:100%;object-fit:cover;display:block}.pe-h-region-meta{display:flex;justify-content:space-between;align-items:baseline;padding:14px 18px 16px}.pe-h-region-name{font-size:21px;font-weight:700}.pe-h-region-count{font-size:13px;color:var(--pe-muted)}';
	$css .= '.pe-h-main{display:flex;flex-wrap:wrap;gap:48px;padding-top:52px}.pe-h-latest{flex:3 1 520px;min-width:0}.pe-h-side{flex:2 1 300px;min-width:0}.pe-h-head{border-bottom:2px solid var(--pe-ink);padding-bottom:10px}.pe-h-head h2{margin:0;font-size:22px;color:var(--pe-ink)}';
	$css .= '.pe-h-row{display:flex;gap:18px;padding:18px 0;border-bottom:1px solid var(--pe-line)}.pe-h-row-img{flex:none;width:140px;height:96px;background:var(--pe-line);overflow:hidden}.pe-h-row-img img{width:100%;height:100%;object-fit:cover;display:block}.pe-h-row-body{min-width:0}.pe-h-row-tag{display:block;font-size:12px;font-weight:700;color:var(--pe-accent)}.pe-h-row-date{margin-left:10px;font-weight:400;color:var(--pe-muted)}.pe-h-row-title{display:block;font-size:18px;font-weight:700;line-height:1.5;margin-top:6px}';
	$css .= '.pe-h-pop{list-style:none;margin:0;padding:0}.pe-h-pop li{margin:0}.pe-h-pop a{display:flex;gap:14px;padding:14px 0;border-bottom:1px solid var(--pe-line)}.pe-h-pop-n{flex:none;width:24px;font-size:20px;font-weight:900;color:var(--pe-accent)}.pe-h-pop-t{font-size:15px;line-height:1.6}';
	$css .= '.pe-h-hubs{background:var(--pe-dark);color:#fff;padding:22px 24px;margin-top:28px}.pe-h-hubs-title{margin:0 0 4px;font-size:13px;font-weight:700;letter-spacing:1px;color:var(--pe-accent-ink)}.pe-h-hubs a{display:flex;justify-content:space-between;padding:12px 0;border-top:1px solid rgba(255,255,255,.18);color:#fff}.pe-h-hubs a:hover{color:var(--pe-accent-ink)}';
	$css .= '@media(max-width:900px){.pe-h-regions{grid-template-columns:repeat(2,minmax(0,1fr))}.pe-h-main{gap:36px}}@media(max-width:560px){.pe-home>section{padding-left:16px;padding-right:16px}.pe-h-hero{padding-top:24px;padding-bottom:20px}.pe-h-regions{gap:12px}.pe-h-region-img{height:88px}.pe-h-region-meta{flex-direction:column;gap:2px;padding:10px 12px 12px}.pe-h-region-name{font-size:18px}.pe-h-main{padding-top:36px;gap:28px}.pe-h-row{gap:14px;padding:14px 0}.pe-h-row-img{width:104px;height:78px}.pe-h-row-title{font-size:16px}.pe-h-pop a{padding:14px 0}.pe-h-hubs a{padding:14px 0}.pe-h-search button{min-width:64px}}';
	echo '<style id="pe-home-base">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}, 97 );

/**
 * 速度優化開關（v2.0）：只作用在單篇文章頁，每一項都可以在 Studio 隨時關掉。
 * lcp：文章第一張圖優先載入（預先載入、不延遲載入），改善「最大內容繪製 LCP」
 * dims：替沒有寬高的圖片補上寬高，載入時版面不跳動，改善「版面位移 CLS」
 * maps：Google 地圖改成點一下才載入，減少第三方程式拖慢頁面（TBT）
 * css：佈景主題的小 CSS 檔直接寫進網頁，不必等另外下載才畫畫面（改善 FCP／LCP）
 */
function pe_speed_opts() {
	$o = get_option( 'pe_speed', array() );
	return array_merge( array( 'lcp' => false, 'dims' => false, 'maps' => false, 'css' => false ), is_array( $o ) ? $o : array() );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/speed', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function () {
				return array( 'ok' => true, 'settings' => pe_speed_opts() );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function ( $req ) {
				$cur = pe_speed_opts();
				foreach ( array( 'lcp', 'dims', 'maps', 'css' ) as $k ) {
					$v = $req->get_param( $k );
					if ( null !== $v ) {
						$cur[ $k ] = (bool) $v;
					}
				}
				update_option( 'pe_speed', $cur, false );
				return array( 'ok' => true, 'settings' => $cur, 'purged' => pe_bridge_purge_caches() );
			},
		),
	) );
} );

// 文章第一張圖的網址（給預先載入用）
function pe_speed_first_img_src( $post ) {
	if ( ! $post ) {
		return '';
	}
	if ( preg_match( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', (string) $post->post_content, $m ) ) {
		return $m[1];
	}
	return '';
}

add_action( 'wp_head', function () {
	$o = pe_speed_opts();
	if ( ! $o['lcp'] || ! is_singular( 'post' ) ) {
		return;
	}
	// 和文章裡那張圖用同一組 srcset／sizes，瀏覽器才會預先下載「同一個尺寸」，不會多抓一張原始大圖
	$post = get_queried_object();
	if ( ! $post || ! preg_match( '/<img\b[^>]*>/i', (string) $post->post_content, $tm ) ) {
		return;
	}
	$tag = $tm[0];
	$get = function ( $attr ) use ( $tag ) {
		return preg_match( '/\s' . $attr . '=["\']([^"\']+)["\']/i', $tag, $m ) ? $m[1] : '';
	};
	$src    = $get( 'src' );
	$srcset = $get( 'srcset' );
	$sizes  = $get( 'sizes' );
	if ( ! $srcset && preg_match( '/wp-image-(\d+)/', $tag, $im ) ) {
		$srcset = (string) wp_get_attachment_image_srcset( (int) $im[1], 'large' );
		$sizes  = $sizes ? $sizes : '(max-width: 1024px) 100vw, 1024px';
	}
	if ( ! $src ) {
		return;
	}
	echo '<link rel="preload" as="image" href="' . esc_url( $src ) . '"' . ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $sizes ? $sizes : '100vw' ) . '"' : '' ) . ' fetchpriority="high">' . "\n";
}, 2 );

function pe_speed_img_size( $src ) {
	$key = 'pe_imgsz_' . md5( $src );
	$c   = get_transient( $key );
	if ( is_array( $c ) ) {
		return $c;
	}
	$size = array();
	$id   = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $src ) );
	if ( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		if ( preg_match( '/-(\d+)x(\d+)\.\w+$/', $src, $mm ) ) {
			$size = array( (int) $mm[1], (int) $mm[2] );
		} elseif ( $meta && ! empty( $meta['width'] ) ) {
			$size = array( (int) $meta['width'], (int) $meta['height'] );
		}
	}
	if ( ! $size ) {
		$up = wp_get_upload_dir();
		if ( 0 === strpos( $src, $up['baseurl'] ) ) {
			$file = $up['basedir'] . substr( $src, strlen( $up['baseurl'] ) );
			if ( is_readable( $file ) ) {
				$gs = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $gs ) {
					$size = array( (int) $gs[0], (int) $gs[1] );
				}
			}
		}
	}
	set_transient( $key, $size, WEEK_IN_SECONDS );
	return $size;
}

add_filter( 'the_content', function ( $html ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	$o     = pe_speed_opts();
	$first = true;
	if ( $o['dims'] || $o['lcp'] ) {
		$html = preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) use ( $o, &$first ) {
			$tag = $m[0];
			if ( $o['dims'] && ! preg_match( '/\swidth=/i', $tag ) && preg_match( '/\ssrc=["\']([^"\']+)["\']/i', $tag, $sm ) ) {
				$sz = pe_speed_img_size( $sm[1] );
				if ( $sz ) {
					$tag = preg_replace( '/^<img\b/i', '<img width="' . $sz[0] . '" height="' . $sz[1] . '"', $tag );
				}
			}
			if ( $o['lcp'] && $first ) {
				$tag = preg_replace( '/\sloading=["\']lazy["\']/i', '', $tag );
				$tag = preg_replace( '/\sfetchpriority=["\'][^"\']*["\']/i', '', $tag );
				$tag = preg_replace( '/^<img\b/i', '<img loading="eager" fetchpriority="high" data-skip-lazy="1"', $tag );
				$tag = preg_match( '/\sclass=["\']/i', $tag ) ? preg_replace( '/\sclass=(["\'])/i', ' class=$1skip-lazy no-lazy ', $tag, 1 ) : preg_replace( '/^<img\b/i', '<img class="skip-lazy no-lazy"', $tag );
			}
			$first = false;
			return $tag;
		}, $html );
	}
	if ( $o['maps'] ) {
		$html = preg_replace_callback( '/<iframe\b[^>]*\bsrc=["\']([^"\']*google\.[^"\']*\/maps[^"\']*)["\'][^>]*><\/iframe>/i', function ( $m ) {
			$GLOBALS['pe_speed_map_used'] = true;
			return '<div class="pe-map-facade" data-src="' . esc_attr( $m[1] ) . '"><button type="button">顯示 Google 地圖</button></div>';
		}, $html );
	}
	return $html;
}, 20 );

// css：把佈景主題（Blocksy）的小樣式檔改成直接寫在網頁裡（inline），省掉「等 CSS 下載完才畫畫面」的時間
// 只處理：前台、佈景主題資料夾或 uploads/blocksy 裡、120KB 以下（壓縮後約 30KB）、沒有 @import 的檔案；每頁最多 160KB
function pe_speed_inline_css_file( $href ) {
	$url  = strtok( (string) $href, '?' );
	$base = content_url();
	if ( 0 === strpos( $url, '//' ) ) {
		$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
	}
	if ( 0 !== strpos( $url, $base . '/' ) ) {
		return null;
	}
	$rel = substr( $url, strlen( $base ) );
	if ( ! preg_match( '#^/(themes/|uploads/blocksy/)#', $rel ) || ! preg_match( '/\.css$/i', $rel ) || false !== strpos( $rel, '..' ) ) {
		return null;
	}
	$path = WP_CONTENT_DIR . $rel;
	if ( ! is_readable( $path ) ) {
		return null;
	}
	$size = filesize( $path );
	if ( ! $size || $size > 122880 ) {
		return null;
	}
	$css = (string) file_get_contents( $path );
	if ( '' === trim( $css ) || false !== stripos( $css, '@import' ) || false !== stripos( $css, '</' ) ) {
		return null;
	}
	// 相對路徑的 url(...) 改成完整網址（字型、圖示才找得到）
	$dir = dirname( $url );
	$css = preg_replace_callback( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ( $m ) use ( $dir ) {
		$u = trim( $m[2] );
		if ( preg_match( '#^(data:|https?:|//|/|\#)#i', $u ) ) {
			return $m[0];
		}
		while ( 0 === strpos( $u, '../' ) ) {
			$u   = substr( $u, 3 );
			$dir = dirname( $dir );
		}
		$u = preg_replace( '#^\./#', '', $u );
		return 'url(' . $m[1] . $dir . '/' . $u . $m[1] . ')';
	}, $css );
	return $css;
}

add_filter( 'style_loader_tag', function ( $tag, $handle, $href, $media ) {
	static $used = 0;
	if ( is_admin() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
		return $tag;
	}
	$o = pe_speed_opts();
	if ( empty( $o['css'] ) || ( $media && 'all' !== $media && 'screen' !== $media ) ) {
		return $tag;
	}
	$css = pe_speed_inline_css_file( $href );
	if ( null === $css || $used + strlen( $css ) > 163840 ) {
		return $tag;
	}
	$used += strlen( $css );
	return '<style id="' . esc_attr( $handle ) . '-css" data-pe-inline="1">' . $css . "</style>\n";
}, 20, 4 );

// 首頁、分類頁、搜尋頁：第一張文章卡片圖是畫面最大的內容，不要延遲載入
add_filter( 'wp_get_attachment_image_attributes', function ( $attr ) {
	static $done = false;
	if ( $done || is_admin() || is_singular() && ! is_front_page() ) {
		return $attr;
	}
	if ( ! ( is_front_page() || is_home() || is_archive() || is_search() ) ) {
		return $attr;
	}
	$o = pe_speed_opts();
	if ( ! $o['lcp'] || empty( $attr['class'] ) || false === strpos( $attr['class'], 'wp-post-image' ) ) {
		return $attr;
	}
	$done                  = true;
	$attr['loading']       = 'eager';
	$attr['fetchpriority'] = 'high';
	$attr['data-skip-lazy'] = '1';
	$attr['class']        .= ' skip-lazy no-lazy';
	// sizes="auto" 只對延遲載入的圖有效；改成立即載入後要拿掉，不然手機會照 100vw 抓大圖
	if ( ! empty( $attr['sizes'] ) ) {
		$attr['sizes'] = preg_replace( '/^\s*auto\s*,\s*/i', '', $attr['sizes'] );
	}
	return $attr;
}, 20 );

add_action( 'wp_footer', function () {
	if ( empty( $GLOBALS['pe_speed_map_used'] ) ) {
		return;
	}
	echo '<style>.pe-map-facade{display:flex;align-items:center;justify-content:center;aspect-ratio:4/3;max-height:420px;background:#eef0f2;border-radius:12px;margin:1.5em auto}.pe-map-facade button{font:inherit;padding:.7em 1.4em;border-radius:999px;border:1px solid #ccc;background:#fff;cursor:pointer}</style>';
	echo '<script>document.addEventListener("click",function(e){var b=e.target.closest(".pe-map-facade button");if(!b)return;var d=b.parentNode,f=document.createElement("iframe");f.src=d.getAttribute("data-src");f.style.cssText="width:100%;height:100%;border:0;border-radius:12px";f.loading="lazy";f.allowFullscreen=true;d.innerHTML="";d.appendChild(f);});</script>' . "\n";
} );


/**
 * 手動廣告版位（v2.2）：在文章指定位置放 AdSense 廣告單元，事先留好固定高度，載入時版面不跳動。
 * - 位置：第 N 個小標題（h2）前、文章結尾；文章太短（段落數不足）不放
 * - 上方標示「廣告」；Google 沒有廣告可放（unfilled）時自動收起
 * - AdSense 主程式由 Site Kit 載入，這裡只放廣告單元
 */
function pe_ads_opts() {
	$o = get_option( 'pe_ads', array() );
	return array_merge(
		array(
			'enabled'   => false,
			'client'    => '',
			'slot'      => '',
			'positions' => array( 'h2:2', 'end' ),
			'min_paras' => 8,
			'height_m'  => 280,
			'height_d'  => 280,
		),
		is_array( $o ) ? $o : array()
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/ads', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function () {
				return array( 'ok' => true, 'settings' => pe_ads_opts() );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'callback'            => function ( $req ) {
				$cur = pe_ads_opts();
				$v   = $req->get_param( 'enabled' );
				if ( null !== $v ) {
					$cur['enabled'] = (bool) $v;
				}
				$client = $req->get_param( 'client' );
				if ( null !== $client ) {
					if ( '' !== $client && ! preg_match( '/^ca-pub-\d{10,20}$/', (string) $client ) ) {
						return new WP_Error( 'pe_ads_client', '發布商 ID 格式不對，應該像 ca-pub-1234567890123456。', array( 'status' => 400 ) );
					}
					$cur['client'] = (string) $client;
				}
				$slot = $req->get_param( 'slot' );
				if ( null !== $slot ) {
					if ( '' !== $slot && ! preg_match( '/^\d{6,20}$/', (string) $slot ) ) {
						return new WP_Error( 'pe_ads_slot', '廣告單元編號應該是一串數字（data-ad-slot）。', array( 'status' => 400 ) );
					}
					$cur['slot'] = (string) $slot;
				}
				$pos = $req->get_param( 'positions' );
				if ( is_array( $pos ) ) {
					$cur['positions'] = array_values( array_unique( array_filter( array_map( 'strval', $pos ), function ( $x ) {
						return 'end' === $x || preg_match( '/^h2:[1-9]$/', $x );
					} ) ) );
					$cur['positions'] = array_slice( $cur['positions'], 0, 3 );
				}
				foreach ( array( 'min_paras' => array( 0, 60 ), 'height_m' => array( 100, 600 ), 'height_d' => array( 90, 600 ) ) as $k => $range ) {
					$n = $req->get_param( $k );
					if ( null !== $n ) {
						$cur[ $k ] = max( $range[0], min( $range[1], (int) $n ) );
					}
				}
				if ( $cur['enabled'] && ( ! $cur['client'] || ! $cur['slot'] ) ) {
					return new WP_Error( 'pe_ads_missing', '要先填發布商 ID 與廣告單元編號才能開啟。', array( 'status' => 400 ) );
				}
				update_option( 'pe_ads', $cur, false );
				return array( 'ok' => true, 'settings' => $cur, 'purged' => pe_bridge_purge_caches() );
			},
		),
	) );
} );

function pe_ads_unit( $o, $where ) {
	return '<div class="pe-ad" data-pe-ad="' . esc_attr( $where ) . '"><div class="pe-ad-label">廣告</div>'
		. '<ins class="adsbygoogle pe-ad-ins" style="display:block" data-ad-client="' . esc_attr( $o['client'] ) . '" data-ad-slot="' . esc_attr( $o['slot'] ) . '"></ins>'
		. '<script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></div>';
}

add_filter( 'the_content', function ( $html ) {
	if ( is_admin() || is_feed() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || ( function_exists( 'is_preview' ) && is_preview() ) ) {
		return $html;
	}
	$o = pe_ads_opts();
	if ( empty( $o['enabled'] ) || ! $o['client'] || ! $o['slot'] ) {
		return $html;
	}
	// 文章太短不放，避免一篇被廣告塞滿
	if ( substr_count( strtolower( $html ), '</p>' ) < (int) $o['min_paras'] ) {
		return $html;
	}
	$GLOBALS['pe_ads_used'] = true;
	$pos = (array) $o['positions'];
	// 在第 N 個 h2 前面插入（從後面往前插，前面的位置才不會跑掉）
	if ( preg_match_all( '/<h2\b/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		$targets = array();
		foreach ( $pos as $p ) {
			if ( preg_match( '/^h2:(\d)$/', $p, $pm ) ) {
				$n = (int) $pm[1];
				if ( $n >= 2 && isset( $m[0][ $n - 1 ] ) ) { // 第 1 個小標題前通常是目錄，不放
					$targets[ $m[0][ $n - 1 ][1] ] = 'h2-' . $n;
				}
			}
		}
		krsort( $targets );
		foreach ( $targets as $offset => $where ) {
			// 小標題前面如果是區塊註解（<!-- wp:heading -->），插在註解前面
			$cut = $offset;
			$before = substr( $html, 0, $offset );
			if ( preg_match( '/<!--\s*wp:heading[^>]*-->\s*$/', $before, $cm ) ) {
				$cut = $offset - strlen( $cm[0] );
			}
			$html = substr( $html, 0, $cut ) . pe_ads_unit( $o, $where ) . "\n" . substr( $html, $cut );
		}
	}
	if ( in_array( 'end', $pos, true ) ) {
		$html .= "\n" . pe_ads_unit( $o, 'end' );
	}
	return $html;
}, 15 );

add_action( 'wp_head', function () {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$o = pe_ads_opts();
	if ( empty( $o['enabled'] ) ) {
		return;
	}
	$hm = (int) $o['height_m'];
	$hd = (int) $o['height_d'];
	// 固定高度：廣告還沒載入前就先留好空間；沒有廣告可放時收起
	echo '<style id="pe-ads-css">.pe-ad{margin-top:2em;margin-bottom:2em;clear:both;text-align:center}.pe-ad-label{font-size:11px;letter-spacing:.1em;opacity:.55;margin-bottom:4px}'
		. '.pe-ad .pe-ad-ins{width:100%;height:' . $hm . 'px}@media(min-width:768px){.pe-ad .pe-ad-ins{height:' . $hd . 'px}}'
		. '.pe-ad .pe-ad-ins[data-ad-status="unfilled"]{display:none!important}.pe-ad:has(.pe-ad-ins[data-ad-status="unfilled"]){display:none}</style>' . "\n";
}, 99 );


/**
 * 自動更新（v2.3）：從公開的 GitHub 儲存庫 mike41907/pickyeaterr-bridge（main 分支）檢查新版本。
 * WordPress「外掛」頁會像其他外掛一樣出現「立即更新」，也可以開啟「自動更新」。
 * 只讀這一個固定的儲存庫；下載走 GitHub 的 https。
 */
define( 'PE_BRIDGE_REPO', 'mike41907/pickyeaterr-bridge' );

function pe_bridge_remote_version( $force = false ) {
	$t = get_site_transient( 'pe_bridge_remote' );
	if ( ! $force && is_array( $t ) ) {
		return $t;
	}
	$out = array( 'version' => '', 'checked' => time(), 'error' => '' );
	$r   = wp_remote_get( 'https://raw.githubusercontent.com/' . PE_BRIDGE_REPO . '/main/pickyeaterr-bridge.php', array( 'timeout' => 10 ) );
	if ( is_wp_error( $r ) ) {
		$out['error'] = $r->get_error_message();
	} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		$out['error'] = 'HTTP ' . wp_remote_retrieve_response_code( $r );
	} elseif ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9.]*)/mi', (string) wp_remote_retrieve_body( $r ), $m ) ) {
		$out['version'] = $m[1];
	}
	set_site_transient( 'pe_bridge_remote', $out, $out['version'] ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $out;
}

function pe_bridge_update_item( $version ) {
	return (object) array(
		'id'           => 'github.com/' . PE_BRIDGE_REPO,
		'slug'         => 'pickyeaterr-bridge',
		'plugin'       => plugin_basename( __FILE__ ),
		'new_version'  => $version,
		'url'          => 'https://github.com/' . PE_BRIDGE_REPO,
		'package'      => 'https://codeload.github.com/' . PE_BRIDGE_REPO . '/zip/refs/heads/main',
		'requires_php' => '7.4',
		'icons'        => array(),
		'banners'      => array(),
	);
}

add_filter( 'pre_set_site_transient_update_plugins', function ( $tr ) {
	if ( ! is_object( $tr ) || empty( $tr->checked ) ) {
		return $tr;
	}
	$file = plugin_basename( __FILE__ );
	$cur  = isset( $tr->checked[ $file ] ) ? $tr->checked[ $file ] : PE_BRIDGE_VERSION;
	$rv   = pe_bridge_remote_version();
	if ( $rv['version'] && version_compare( $rv['version'], $cur, '>' ) ) {
		$tr->response[ $file ] = pe_bridge_update_item( $rv['version'] );
		unset( $tr->no_update[ $file ] );
	} else {
		// 放在 no_update：外掛頁才會出現「啟用自動更新」
		$tr->no_update[ $file ] = pe_bridge_update_item( $cur );
		unset( $tr->response[ $file ] );
	}
	return $tr;
} );

// GitHub 的 zip 解開後資料夾叫 pickyeaterr-bridge-main，改回原本的 pickyeaterr-bridge，外掛才不會被停用
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	if ( empty( $hook_extra['plugin'] ) || plugin_basename( __FILE__ ) !== $hook_extra['plugin'] ) {
		return $source;
	}
	global $wp_filesystem;
	$want = trailingslashit( $remote_source ) . 'pickyeaterr-bridge/';
	if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
		return $source;
	}
	if ( ! $wp_filesystem || ! $wp_filesystem->exists( trailingslashit( $source ) . 'pickyeaterr-bridge.php' ) ) {
		return new WP_Error( 'pe_bridge_package', '下載的檔案裡找不到 pickyeaterr-bridge.php，已取消更新。' );
	}
	if ( $wp_filesystem->move( $source, $want, true ) ) {
		return $want;
	}
	return new WP_Error( 'pe_bridge_rename', '無法調整外掛資料夾名稱，已取消更新。' );
}, 10, 4 );

// 外掛頁「檢視詳細資料」
add_filter( 'plugins_api', function ( $res, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'pickyeaterr-bridge' !== $args->slug ) {
		return $res;
	}
	$rv = pe_bridge_remote_version();
	return (object) array(
		'name'          => 'Pickyeaterr Studio Bridge',
		'slug'          => 'pickyeaterr-bridge',
		'version'       => $rv['version'] ? $rv['version'] : PE_BRIDGE_VERSION,
		'author'        => 'Pickyeaterr',
		'homepage'      => 'https://github.com/' . PE_BRIDGE_REPO,
		'download_link' => 'https://codeload.github.com/' . PE_BRIDGE_REPO . '/zip/refs/heads/main',
		'requires_php'  => '7.4',
		'sections'      => array(
			'description' => '讓 Pickyeaterr Studio 安全地更新文章、樣式、速度與廣告設定的橋接外掛。',
			'changelog'   => '每一版的更新內容請看 Studio「設定 → 系統與更新」。',
		),
	);
}, 10, 3 );

// Studio 用：查目前版本與 GitHub 上的最新版本；check=1 時立刻重新檢查（讓外掛頁馬上出現「立即更新」）
add_action( 'rest_api_init', function () {
	register_rest_route( 'pe-bridge/v1', '/update-check', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_others_posts' );
		},
		'callback'            => function ( $req ) {
			$force = (bool) $req->get_param( 'check' );
			$rv    = pe_bridge_remote_version( $force );
			if ( $force ) {
				delete_site_transient( 'update_plugins' );
			}
			$auto = (array) get_site_option( 'auto_update_plugins', array() );
			return array(
				'ok'          => true,
				'current'     => PE_BRIDGE_VERSION,
				'latest'      => $rv['version'],
				'error'       => $rv['error'],
				'checked'     => gmdate( 'c', (int) $rv['checked'] ),
				'hasUpdate'   => $rv['version'] && version_compare( $rv['version'], PE_BRIDGE_VERSION, '>' ),
				'autoUpdate'  => in_array( plugin_basename( __FILE__ ), $auto, true ),
				'pluginsUrl'  => admin_url( 'plugins.php' ),
			);
		},
	) );
} );

