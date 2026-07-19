<?php
/**
 * Shell del portal: el contenido lo renderiza assets/portal.js contra la
 * API REST. Mantener mínimo — sin lógica de datos aquí.
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="alb-ep-portal" class="alb-ep" data-view="services">
	<header class="alb-ep__header">
		<div>
			<p class="alb-ep__hello" data-ep="hello"></p>
			<h2 class="alb-ep__title" data-ep="title"></h2>
		</div>
	</header>

	<nav class="alb-ep__tabs" data-ep="tabs" aria-label="<?php esc_attr_e( 'Secciones del portal', 'alb-employee-portal' ); ?>"></nav>

	<main class="alb-ep__view" data-ep="view" aria-live="polite"></main>

	<div class="alb-ep__toast" data-ep="toast" role="status" hidden></div>
</div>
