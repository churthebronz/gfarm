<?php if(!defined('FastCore')){ exit('Oops!');}

######################################
# Мульти-уровневая реферальная программа
# Версия: v1 - 20/03/2020
# Автор: Jumast - jumast@ya.ru
######################################

class income_ref {
	var $db = NULL;

	function __construct($db){
		$this->db = $db;
	}

function uRef($uid, $sum){

		$uid = intval($uid);
		$sum = round($sum, 4);

		# Глубина рефералов
		$r1 = $this->db->query('SELECT rid FROM db_users WHERE id = '.$uid.' LIMIT 1')->fetchArray();
		$rid1 = $r1['rid'];

		$r2 = $this->db->query('SELECT rid FROM db_users WHERE id = '.$rid1.' LIMIT 1')->fetchArray();
		$rid2 = $r2['rid'];

		$r3 = $this->db->query('SELECT rid FROM db_users WHERE id = '.$rid2.' LIMIT 1')->fetchArray();
		$rid3 = $r3['rid'];

		# Начисляем проценты
		$sum1 = ($sum * 0.05);
		$sum2 = ($sum * 0.00);
		$sum3 = ($sum * 0.00);

		$this->db->query('UPDATE db_users SET money_p = money_p + '.$sum1.', income = income + '.$sum1.' WHERE id = '.$rid1.'');
		$this->db->query('UPDATE db_users SET money_p = money_p + '.$sum2.', income = income + '.$sum2.' WHERE id = '.$rid2.'');
		$this->db->query('UPDATE db_users SET money_p = money_p + '.$sum3.', income = income + '.$sum3.' WHERE id = '.$rid3.'');

		$this->db->query('UPDATE db_users SET ref_to = ref_to + '.$sum1.' WHERE id = '.$uid.'');
	}
}
?>