<?php
/**
 * Desinstalación de ALB Employee Portal.
 *
 * DELIBERADAMENTE NO se eliminan las tablas alb_ep_* : contienen metadatos
 * de servicios, propuestas y el registro de auditoría (requisito permanente
 * 4: sin pérdida de datos). Si algún día se quiere purgar todo, hacerlo
 * manualmente con conocimiento de causa.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
