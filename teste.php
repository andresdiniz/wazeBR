<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$output = shell_exec('wget -qO- --no-check-certificate "https://servicos.dnit.gov.br/sgplan/apigeo/rotas/localizarkm?lng=-43.79944324493409&lat=-20.734276310454153&r=250&data=2025-1-21"');
    echo $output;