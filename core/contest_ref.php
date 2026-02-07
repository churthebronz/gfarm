<?PHP

class contest_ref{

	var $db = NULL;
	var $compd = array();

	# Конструктор
	function __construct($db){
		$this->db = $db;
		$this->compd = $this->CompData();
	}

	# Данные конкурса
	function CompData(){
	
		$this->db->Query("SELECT * FROM db_contest_ref WHERE status = '0' LIMIT 1");	
		if($this->db->NumRows() > 0){
			return $this->db->FetchArray();	
		}else return false;
	}
	
	# Обновляем очки пользователя
	function UpdatePoints($uid, $sum){

	$uid = intval($uid);
	$sum = round($sum*100, 2);

		if($this->compd['date_add'] >= 0 AND $this->compd['date_end'] > time()){
		
			$this->db->Query("SELECT referer, rid, reg FROM db_users WHERE id = '{$uid}'");
			$ret_d = $this->db->FetchArray();

	//		if($ret_d['reg'] >= $this->compd["date_add"]){
			
			# Проверяем есть ли пользователь в конкурсе
			$rid = $ret_d['rid'];
			$ref = $ret_d['referer'];

			$select = $this->db->query("SELECT * FROM db_contest_ref_u WHERE uid = '{$rid}'")->numRows();
			if($select == 1){

				$ret_d = $this->db->FetchArray();
				$this->db->Query("UPDATE db_contest_ref_u SET points = points + '$sum' WHERE uid = '{$rid}'");

			} else $this->db->Query("INSERT INTO db_contest_ref_u (login, uid, points) VALUES ('$ref','{$rid}','$sum')");
				return true;
//			}else return false;
		} else return false;
	}
}

?>