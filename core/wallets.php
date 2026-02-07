<?php if(!defined('FastCore')){echo ('Выявлена попытка взлома!');exit();}

class wallets {
	# Payeer фильтрация
	public function payeer_wallet($purse){
		if( substr($purse,0,1) != "P") return false;
				if(!preg_match("#^[P0-9]{5,16}$#", $purse)) return false;	
	return $purse;
	}
	# TRON фильтрация
	public function tron_wallet($purse){
		if( substr($purse,0,1) != "T") return false;
				if(!preg_match("#^[A-Za-z0-9]{12,40}$#", $purse)) return false;	
	return $purse;
	}
	# TRON USDT фильтрация
	public function tron_trc20_wallet($purse){
		if( substr($purse,0,1) != "T") return false;
				if(!preg_match("#^[A-Za-z0-9]{12,40}$#", $purse)) return false;	
	return $purse;
	}
	# DOGE фильтрация
	public function dogecoin_wallet($purse){
		if( substr($purse,0,1) != "D") return false;
				if(!preg_match("#^[A-Za-z0-9]{12,40}$#", $purse)) return false;	
	return $purse;
	}
	# Bitcoin фильтрация
	public function bitcoin_wallet($purse){
		if( !preg_match("#^[A-Za-z0-9]{32,62}$#",$purse) ) return false;
         return $purse;
	}
	# Bitcoin Cash фильтрация
	public function bitcoincash_wallet($purse){
		if( !preg_match("#^[A-Za-z0-9\:]{32,62}$#",$purse) ) return false;
         return $purse;
	}
	# Litecoin фильтрация
	public function litecoin_wallet($purse){
		if( !preg_match("#^([ML]+|ltc1)[A-Za-z0-9]{20,65}$#",$purse) ) return false;
         return $purse;
	}

	# Ethereum фильтрация
	public function ethereum_wallet($purse){
		if( !preg_match("#^([0x])[A-Za-z0-9]{40,44}$#",$purse) ) return false;
         return $purse;
	}
	# Binance фильтрация
	public function binancecoin_wallet($purse){
		if( !preg_match("#^([0x])[A-Za-z0-9]{40,44}$#",$purse) ) return false;
         return $purse;
	}

	# Dash фильтрация
	public function dash_wallet($purse){
		if( !preg_match("#^[A-Za-z0-9]{32,34}$#",$purse) ) return false;
         return $purse;
	}
	# Visa фильтрация
	public function visa_wallet($purse){
		if( !preg_match("#^41001[0-9]{12,15}$#",$purse) ) return false;
         return $purse;
	}
	# PerfectMoney фильтрация
	public function perfect_wallet($purse){
		if( !preg_match("#^([U]{1}[\d]{5,10})$#",$purse) ) return false;
         return $purse;
	}
}
?>