<?php
include_once('config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/Smarty/Smarty.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/settings/menu_list.php' );
include_once( INSTALL_PATH . '/util.php' );

$settings = Settings::factory();

$week_tb = array( '日', '月', '火', '水', '木', '金', '土' );


$order = "";
$search = "";
$category_id = 0;
$station = 0;

// mysql_real_escape_stringより先に接続しておく必要がある
$dbh = @mysql_connect( $settings->db_host, $settings->db_user, $settings->db_pass );

// $options = "WHERE complete='1'";
$options = "WHERE starttime < '". date('Y-m-d H:i:s')."'";	// ながら再生は無理っぽい？

if(isset( $_GET['key']) ) {
	$options .= " AND autorec ='".mysql_real_escape_string(trim($_GET['key']))."'";
}

if(isset( $_POST['do_search'] )) {
	if( isset($_POST['search'])){
		if( $_POST['search'] != "" ) {
			$search = $_POST['search'];
//			$options .= " AND CONCAT(title,description) like '%".mysql_real_escape_string($_POST['search'])."%'";
			foreach( explode( ' ', trim($search) ) as $key ){
				$k_len = strlen( $key );
				if( $k_len>1 && $key[0]==='-' ){
					$k_len--;
					$key = substr( $key, 1 );
					$options .= " AND CONCAT(title,' ', description) not like ";
				}else
					$options .= " AND CONCAT(title,' ', description) like ";
				if( $key[0]==='"' && $k_len>2 && $key[$k_len-1]==='"' )
					$key = substr( $key, 1, $k_len-2 );
				$options .= "'%".mysql_real_escape_string( $key )."%'";
			}
		}
	}
	if( isset($_POST['category_id'])) {
		if( $_POST['category_id'] != 0 ) {
			$category_id = $_POST['category_id'];
			$options .= " AND category_id = '".$_POST['category_id']."'";
		}
	}
	if( isset($_POST['station'])) {
		if( $_POST['station'] != 0 ) {
			$station = $_POST['station'];
			$options .= " AND channel_id = '".$_POST['station']."'";
		}
	}
}


$options .= ' ORDER BY starttime DESC';

try{
	$rvs = DBRecord::createRecords(RESERVE_TBL, $options );
	$records = array();
	foreach( $rvs as $r ) {
		$cat = new DBRecord(CATEGORY_TBL, 'id', $r->category_id );
		$arr = array();
		$arr['id'] = $r->id;
		if( $r->channel_id ){
			try{
				$ch  = new DBRecord(CHANNEL_TBL,  'id', $r->channel_id );
				$arr['station_name'] = $ch->name;
			}catch( exception $e ){
				$r->channel_id = 0;
				$r->update();
				$arr['station_name'] = 'lost';
			}
		}else
			$arr['station_name'] = 'lost';
		$start_time = toTimestamp($r->starttime);
		$end_time   = toTimestamp($r->endtime);
		$arr['starttime'] = date( 'Y/m/d(', $start_time ).$week_tb[date( 'w', $start_time )].')<br>'.date( 'H:i:s', $start_time );
		$arr['duration']  = date( 'H:i:s', $end_time-$start_time-9*60*60 );
		$movie_found = false;
		$moviepath = INSTALL_PATH.$settings->spool.'/'.$r->path;
		$moviefile = pathinfo( $r->path, PATHINFO_BASENAME );
		if( !@is_dir( $moviepath ) && @is_readable( $moviepath ) ){
			$movie_found = true;
			$arr['spool'] = 'main';
		}else
		if( use_alt_spool() ){
			$altmoviepath = $settings->alt_spool.'/'.$moviefile;
			if( !@is_dir( $altmoviepath ) && @is_readable( $altmoviepath ) ){
				$movie_found = true;
				$arr['spool'] = 'alt';
			}
		}
		if( $movie_found ){
			$arr['asf'] = $settings->install_url.'/viewer.php?reserve_id='.$r->id;
		}else{
			$arr['asf'] = '';
			$arr['spool'] = '';
		}
		$arr['title'] = htmlspecialchars($r->title,ENT_QUOTES);
		$arr['description'] = htmlspecialchars($r->description,ENT_QUOTES);
		$thumbfile = $moviefile.'.jpg';
		$thumbpath = INSTALL_PATH.$settings->thumbs.'/'.$thumbfile;
		if ( is_readable( $thumbpath ) ){
			$arr['thumb'] = '<img src="'.$settings->install_url.$settings->thumbs.'/'.rawurlencode($thumbfile).'" />';
		}else{
			$arr['thumb'] = '-';
		}
		$arr['cat'] = $cat->name_en;
		$arr['mode'] = $RECORD_MODE[$r->mode]['name'];
		$arr['keyword'] = putProgramHtml( $arr['title'], '*', 0, $r->category_id, 16 );
		$arr['key_id']  = (int)$r->autorec;
		if( DBRecord::countRecords( KEYWORD_TBL, "WHERE id = '".$arr['key_id']."'" ) == 0 )
			$arr['key_id'] = 0;
		array_push( $records, $arr );
	}

	$crecs = DBRecord::createRecords(CATEGORY_TBL );
	$cats = array();
	$cats[0]['id'] = 0;
	$cats[0]['name'] = 'すべて';
	$cats[0]['selected'] = $category_id == 0 ? 'selected' : "";
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id'] = $c->id;
		$arr['name'] = $c->name_jp;
		$arr['selected'] = $c->id == $category_id ? 'selected' : "";
		array_push( $cats, $arr );
	}

	$stations = array();
	$stations[0]['id'] = 0;
	$stations[0]['name'] = 'すべて';
	$stations[0]['selected'] = (! $station) ? 'selected' : "";
	$crecs = DBRecord::createRecords( CHANNEL_TBL, "WHERE type = 'GR' AND skip = '0' ORDER BY id" );
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id'] = $c->id;
		$arr['name'] = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : "";
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, "WHERE type = 'BS' AND skip = '0' ORDER BY sid" );
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id'] = $c->id;
		$arr['name'] = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : "";
		array_push( $stations, $arr );
	}
	$crecs = DBRecord::createRecords( CHANNEL_TBL, "WHERE type = 'CS' AND skip = '0' ORDER BY sid" );
	foreach( $crecs as $c ) {
		$arr = array();
		$arr['id'] = $c->id;
		$arr['name'] = $c->name;
		$arr['selected'] = $station == $c->id ? 'selected' : "";
		array_push( $stations, $arr );
	}

	// スプール空き容量
	$free_spaces = get_spool_free_space();
	$free_size = $free_spaces['free_hsize'];
	$free_time = $free_spaces['free_time'];
	$ts_stream_rate = $free_spaces['ts_stream_rate'];
	if( use_alt_spool() ){
		$spool_disks = $free_spaces['spool_disks'];
		foreach( $spool_disks as $disk ){
			if( $disk['name'] === 'alt' && $disk['path'] === (string)$settings->alt_spool ){
				$alt_free_size = $disk['hsize'];
				$alt_free_time = $disk['time'];
				break;
			}
		}
	}

	$link_add = '';
	if( (int)$settings->gr_tuners > 0 )
		$link_add .= '<option value="index.php">地上デジタル番組表</option>';
	if( (int)$settings->bs_tuners > 0 ){
		$link_add .= '<option value="index.php?type=BS">BSデジタル番組表</option>';
		if( (boolean)$settings->cs_rec_flg )
			$link_add .= '<option value="index.php?type=CS">CSデジタル番組表</option>';
	}
	if( EXTRA_TUNERS )
		$link_add .= '<option value="index.php?type=EX">'.EXTRA_NAME.'番組表</option>';

	$smarty = new Smarty();
	$smarty->assign('sitetitle','録画済一覧');
	$smarty->assign( 'records', $records );
	$smarty->assign( 'search', $search );
	$smarty->assign( 'stations', $stations );
	$smarty->assign( 'cats', $cats );
	$smarty->assign( 'use_thumbs', $settings->use_thumbs );
	$smarty->assign( 'spool', (string)$settings->spool );
	$smarty->assign( 'free_size', $free_size );
	$smarty->assign( 'free_time', $free_time );
	$smarty->assign( 'ts_rate', $ts_stream_rate );
	$smarty->assign( 'use_alt_spool', isset( $alt_free_size ) && isset( $alt_free_time ) );
	$smarty->assign( 'alt_spool', (string)$settings->alt_spool );
	$smarty->assign( 'alt_spool_writable', is_alt_spool_writable() );
	$smarty->assign( 'alt_free_size', isset( $alt_free_size ) ? $alt_free_size : 0 );
	$smarty->assign( 'alt_free_time', isset( $alt_free_time ) ? $alt_free_time : 0 );
	$smarty->assign( 'link_add', $link_add );
	$smarty->assign( 'menu_list', $MENU_LIST );
	$smarty->display('recordedTable.html');
}
catch( exception $e ) {
	exit( $e->getMessage() );
}
?>
