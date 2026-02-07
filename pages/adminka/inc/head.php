<?php if(!defined('FastCore')){exit();} ?><!doctype html>
<html lang="en">
<head>
	<title><?= isset($opt['title']) ? htmlspecialchars((string)$opt['title']) : 'Admin'; ?> • GreenFarm</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="shortcut icon" href="/img/favicon.ico" type="image/x-icon">
	<link rel="stylesheet" href="/assets/css/bootstrap.min.css">
	<link rel="stylesheet" href="/assets/css/flags.css">
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css">
	<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.5.2/jquery.min.js"></script>

	<script src="/js/telegram_auth.js"></script>
	<script src="/js/vx_toast.js"></script>
	<script src="/js/live_update.js"></script>

	<style>
		:root{
			--vx-bg: #070b14;
			--vx-panel: rgba(15,23,42,.55);
			--vx-panel-2: rgba(2,6,23,.65);
			--vx-border: rgba(148,163,184,.14);
			--vx-text: rgba(226,232,240,.92);
			--vx-muted: rgba(226,232,240,.66);
			--vx-gold: rgba(251,191,36,.95);
			--vx-cyan: rgba(0,255,224,.65);
			--vx-danger: rgba(248,113,113,.95);
			--vx-ok: rgba(34,197,94,.95);
		}

		body.vx-admin{
			background:
				radial-gradient(900px 520px at 10% 0%, rgba(251,191,36,.10), transparent 55%),
				radial-gradient(1100px 700px at 92% 0%, rgba(0,255,224,.08), transparent 60%),
				linear-gradient(180deg, #050815, #070b14 35%, #050815);
			color: var(--vx-text);
			font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji";
			min-height: 100vh;
		}

		.vx-admin a{ color: rgba(226,232,240,.9); }
		.vx-admin a:hover{ color: #fff; text-decoration: none; }

		.vx-admin-shell{ padding: 14px; }
		@media (min-width: 992px){ .vx-admin-shell{ padding: 18px; } }

		/* Layout (bootstrap-version safe)
		   We do NOT rely on Bootstrap's grid/row behavior.
		   Some installs ship Bootstrap 5 where .row is flex; adding gaps can cause wrapping.
		   We use our own flex layout so the sidebar never drops above the content. */
		.vx-admin-row{ display:flex; gap:14px; align-items:flex-start; }
		.vx-admin-side{ flex:0 0 260px; }
		.vx-admin-main{ flex:1 1 auto; min-width: 0; }
		@media (max-width: 991px){
			.vx-admin-row{ flex-direction: column; }
			.vx-admin-side{ flex: 0 0 auto; width: 100%; }
		}

		/* Sidebar */
		.vx-admin-side{
			border-radius: 18px;
			border: 1px solid var(--vx-border);
			background: linear-gradient(180deg, var(--vx-panel), var(--vx-panel-2));
			box-shadow: 0 16px 45px rgba(0,0,0,.45);
			overflow: hidden;
		}

		/* Admin nav list (bootstrap-version safe)
		   - Bootstrap 3's .navbar-nav floats items horizontally.
		   - We force a vertical, full-width list regardless of bootstrap version. */
		.vx-admin-nav{
			list-style:none;
			margin:0;
			padding:0;
		}
		.vx-admin-nav > li{
			float:none !important;
			display:block;
			width:100%;
		}
		.vx-admin-nav .nav-link{
			display:block;
			width:100%;
		}
		.vx-admin-brand{
			padding: 14px 14px 12px;
			border-bottom: 1px solid rgba(148,163,184,.12);
			background:
				radial-gradient(800px 260px at 20% 0%, rgba(251,191,36,.14), transparent 55%),
				linear-gradient(180deg, rgba(2,6,23,.25), rgba(2,6,23,.45));
		}
		.vx-admin-brand .t1{ font-weight: 900; letter-spacing: .2px; }
		.vx-admin-brand .t2{ font-size: 12px; opacity: .72; margin-top: 2px; }

		.vx-admin-nav .nav-item{
			border-bottom: 1px solid rgba(148,163,184,.08);
		}
		.vx-admin-nav .nav-link{
			padding: 10px 12px;
			font-weight: 700;
			opacity: .86;
		}
		.vx-admin-nav .nav-link i{ width: 18px; text-align:center; margin-right: 8px; opacity: .9; }
		.vx-admin-nav .nav-link:hover{
			background: rgba(148,163,184,.08);
			opacity: 1;
		}
		.vx-admin-nav .nav-link.active{
			background: rgba(251,191,36,.12);
			border-left: 3px solid var(--vx-gold);
			opacity: 1;
		}

		/* Topbar */
		.vx-admin-top{
			border-radius: 18px;
			border: 1px solid var(--vx-border);
			background: linear-gradient(180deg, rgba(15,23,42,.55), rgba(2,6,23,.55));
			box-shadow: 0 16px 45px rgba(0,0,0,.35);
			padding: 10px 12px;
			display:flex;
			align-items:center;
			justify-content: space-between;
			gap: 12px;
			margin-bottom: 12px;
		}
		.vx-admin-top .left a{ opacity:.9; }
		.vx-admin-top .left a:hover{ opacity:1; }
		.vx-admin-top .sep{ opacity:.35; margin: 0 8px; }
		.vx-admin-pill{
			display:inline-flex; align-items:center; gap:8px;
			padding: 6px 10px;
			border-radius: 999px;
			border: 1px solid var(--vx-border);
			background: rgba(15,23,42,.45);
			font-weight: 800;
			font-size: 12px;
			color: rgba(226,232,240,.86);
		}
		.vx-admin-pill.ok{ border-color: rgba(34,197,94,.25); background: rgba(34,197,94,.10); }
		.vx-admin-pill.warn{ border-color: rgba(251,191,36,.25); background: rgba(251,191,36,.10); }
		.vx-admin-pill.bad{ border-color: rgba(248,113,113,.25); background: rgba(248,113,113,.10); }

		/* Content */
		.vx-admin-content{
			border-radius: 18px;
			border: 1px solid var(--vx-border);
			background: linear-gradient(180deg, rgba(15,23,42,.40), rgba(2,6,23,.40));
			box-shadow: 0 16px 45px rgba(0,0,0,.28);
			padding: 14px;
			min-height: calc(100vh - 110px);
		}
		@media (min-width: 992px){ .vx-admin-content{ padding: 18px; } }

		/* Bootstrap component tone overrides */
		.table{ color: rgba(226,232,240,.9); }
		.table thead th{ border-color: rgba(148,163,184,.18) !important; }
		.table td, .table th{ border-color: rgba(148,163,184,.10) !important; }
		.table-striped tbody tr:nth-of-type(odd){ background: rgba(148,163,184,.04); }
		.form-control, .custom-select{
			background: rgba(15,23,42,.35);
			border: 1px solid rgba(148,163,184,.18);
			color: rgba(226,232,240,.92);
			border-radius: 12px;
		}
		.form-control:focus{
			background: rgba(15,23,42,.45);
			border-color: rgba(251,191,36,.35);
			box-shadow: 0 0 0 .2rem rgba(251,191,36,.10);
			color: rgba(226,232,240,.95);
		}
		.btn{
			border-radius: 999px;
			font-weight: 800;
		}
		.btn-primary{ background: rgba(251,191,36,.95); border-color: rgba(251,191,36,.85); color:#111827; }
		.btn-primary:hover{ background: rgba(251,191,36,1); border-color: rgba(251,191,36,1); color:#0b1220; }
		.btn-danger{ background: rgba(248,113,113,.95); border-color: rgba(248,113,113,.85); }
		.alert{ border-radius: 14px; border: 1px solid rgba(148,163,184,.18); background: rgba(15,23,42,.35); color: rgba(226,232,240,.9); }
		.card{ border-radius: 16px; border: 1px solid rgba(148,163,184,.14); background: rgba(15,23,42,.30); color: rgba(226,232,240,.92); }
		</style>
</head>
<body class="vx-admin">
<div class="container-fluid vx-admin-shell">
<div class="vx-admin-row">
