<?php
/**
 * 1pay.cx for OpenCart 4 — admin model: creates the table that maps a
 * 1pay.cx payment id to an order.
 *
 * The table is deliberately NOT dropped on uninstall: it is the only link
 * from a payment id back to its order, and uninstall/reinstall is a common
 * troubleshooting step. Dropping it would make every in-flight payment
 * unrecoverable (the notify would answer "unknown payment" and the buyer
 * would be bounced to the cart after paying). OpenCart core removes the
 * settings; the merchant can drop `onepaycx_order` by hand if they really
 * want it gone.
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

namespace Opencart\Admin\Model\Extension\Onepaycx\Payment;

class Onepaycx extends \Opencart\System\Engine\Model {
	public function install(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "onepaycx_order` (
			`payment_id` VARCHAR(64) NOT NULL,
			`order_id` INT(11) NOT NULL,
			`amount` VARCHAR(32) NOT NULL,
			`currency` VARCHAR(3) NOT NULL,
			`status` VARCHAR(16) NOT NULL DEFAULT 'pending',
			`return_key` VARCHAR(64) NOT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`payment_id`),
			KEY `order_id` (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}

	/** Intentionally keeps `onepaycx_order` (see the class comment). */
	public function uninstall(): void {
	}
}
