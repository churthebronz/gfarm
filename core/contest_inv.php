<?PHP

class contest_inv{
	var $db = NULL;
	var $compd = array();

	# Конструктор
	function __construct($db){
		$this->db = $db;
		$this->compd = $this->CompData();
	}

	# Данные конкурса
	function CompData(){
	
		$this->db->Query("SELECT * FROM db_contest_inv WHERE status = '0' LIMIT 1");	
		if($this->db->NumRows() > 0){
			return $this->db->FetchArray();	
		}else return false;
	}
	
	# Обновляем очки пользователя
	function UpdatePoints($uid, $sum){

	$uid = intval($uid);
	$sum = round($sum, 2);

		if($this->compd['date_add'] >= 0 AND $this->compd['date_end'] > time()){
		
			$this->db->Query("SELECT * FROM db_users WHERE id = '{$uid}'");
			$ret_d = $this->db->FetchArray();

			# Проверяем есть ли пользователь в конкурсе
			$login = $ret_d['login'];

			$select = $this->db->query("SELECT * FROM db_contest_inv_u WHERE uid = '{$uid}'")->numRows();
			if($select == 1){

				$ret_d = $this->db->FetchArray();
				$user_id = $ret_d['uid'];

				$this->db->Query("UPDATE db_contest_inv_u SET points = points + '$sum' WHERE uid = '{$user_id}'");

			} else $this->db->Query("INSERT INTO db_contest_inv_u (login, uid, points) VALUES ('$login','{$uid}','$sum')");
				return true;
		} else return false;
	}
}

?>