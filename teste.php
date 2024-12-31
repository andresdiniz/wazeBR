
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<title>Gráficos para PCDs</title>

	<link  href="css/estilo_.css" rel="stylesheet" type="text/css" />

	<script type='text/javascript' src='../js/jquery.min.js'></script>
	<script src="../js/highcharts.new.js"></script>
	<script src="../js/exporting.new.js"></script>

     <link href="css/bootstrap.min.css" rel="stylesheet" media="screen">
     <link href="css/personalizacao.css" rel="stylesheet" media="screen">
     <script src="js/bootstrap.min.js"></script>

	<style type='text/css'>
		.div1 { float:left; width:100%; }
		.btntop { border-top:1px solid #456c99; border-left:1px solid #456c99;  border-bottom:1px solid #7da2c8; border-right:1px solid #7da2c8; -moz-border-radius:5px; border-radius:6px; padding:5px; padding-left:10px; padding-right:10px; cursor:pointer; text-shadow: 0px 1px 0px #315785; font-weight:bold; color:#fff; background-image:url(img/btntop.jpg); background-repeat:repeat-x; }
		.btntop:hover {background-image:url(img/btntoph.jpg); background-repeat:repeat-x; color:#315785; text-shadow: 0px 1px 0px #90bae2;}
	</style>

	
			<script type='text/javascript'>
				$(document).ready(function() { 						Grafico(2,'mm','Precipitação Acumulada nas Últimas 4 horas | Estação: Centro (311830401A)','CEMADEN',['2024-12-31  15h00 UTC','2024-12-31  16h00 UTC','2024-12-31  17h00 UTC','2024-12-31  18h00 UTC','2024-12-31  19h00 UTC','2024-12-31  19h10 UTC','2024-12-31  19h20 UTC','2024-12-31  19h30 UTC','2024-12-31  19h40 UTC','2024-12-31  19h50 UTC','2024-12-31  20h00 UTC'],'2024-12-31 ',[0,0,0,0,0.2,2.95,9.14,7.71,6.1,4.53,0.79],'Acumulada',[0,0,0,0,0.2,3.15,12.29,20,26.09,30.62,31.41]); 				});
			</script>
	<script type='text/javascript'>//<![CDATA[

		//CAPTURA FUSO HORARIO
			var todayLocal = new Date();
			var todayGMT = new Date();
			var fuso = todayLocal.getTimezoneOffset()/60;
			var fuso = 0;
			todayGMT.setHours(todayGMT.getHours()+fuso); // converte hr atual p/ GMT

		//'GET'
		// Processo: pega a url e coloca na variavel url || converte em String || converte em um array separando pelos (idpcd=|hr=|dia=)
			var url = location.search;
			var idEstacao = url.toString().split("idpcd=")[1].split("&")[0];

			if (url.length > 11) {
				var select_hr = url.toString().split("hr=")[1].split("&")[0];
			} else {
				var select_hr = 24 + fuso; //default
			}

			if (url.length > 17){
				var select_dia = url.toString().split("dia=")[1];
			} else {
				var select_dia = todayLocal.getHours() + (fuso*2) + (7*24); //default
			}
		// FIM: GET

		// Cabecalho - Define Variaveis 'Globais'(Grafico Horario: Captura Dados | Grafico Linhas: Imprime)
			var estacao = '';
			var codEstacao = '';
			var	tipoEstacao = '';
			var	cidade = '';
			var	estado = '';
			var	dataAtual = '';
			var	horaAtual = '';
		// FIM: Cabecalho - Define Variaveis

		//var path = "http://sjc.salvar.cemaden.gov.br/WebServiceSalvar-war/resources/";
		//var path = "http://mapainterativo.cemaden.gov.br/MapaInterativoWS/resources/";
		var path = "https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/";
				var area = 2; // ponto inicial do grafico de Linhas (container)
		var chartHorario;
		var chartDiario;
		var chartLinha;

		var charts = {
			chartHorario: null,
			chartDiario : null,
			chartLinha: null
		}

		var cor = new Array('#4572A7','#AA4643','#4B8F56','#C9C473','#DA9054');
		var geral = [
				   //['area','chart_tipo','tipo','titulo','medida','valor_atual']
					 [0,'chartHorario','horario','Precipitação','mm',null],
					 [1,'chartDiario','diario','Precipitação','mm',null]
					];

		$(document).ready(function() {

			// selects
				$("#selhorario").change(function() {
					select_hr = $(this).val(); //captura valor hr (reload)
					cria_grafico_horario($(this).val());
				});

				$("#seldiario").change(function() {
					select_dia = $(this).val(); //captura valor dia (reload)
					cria_grafico_diario($(this).val());
				});

			// cria_grafico
				for (var i=0; i<geral.length; i++) {
					if (i < 2) {
						eval("cria_grafico_"+geral[i][2]+"($(\"#sel"+geral[i][2]+"\").val());");
					}
				}

			// reload (a cada 2 minutos)
				setTimeout(function () {
					window.location = location.pathname + '?idpcd='+idEstacao+'&hr=' +select_hr+'&dia='+select_dia;},
					120000);

		});

		$(window).resize({charts:charts}, function(event) {
			for(var i=0; i<geral.length; i++) {
				eval("event.data.charts."+geral[i][1]+".options.exporting.width=$(\"#container"+geral[i][0]+"\").width()+200;");
			}
		});

		function roundNumber(num, dec) {
			var result = Math.round(num*Math.pow(10,dec))/Math.pow(10,dec);
			return result;
		}

		/********************************************************************** GRAFICO HORARIO ******************************************************************************/

		function cria_grafico_horario(horas){

			var url = path+geral[0][2]+"/"+idEstacao+"/"+(horas-1);
			//console.log("url "+url);

			$.ajax({
				url: url,
				context: this,
				crossDomain: true,
				// cache: false,
				dataType: 'json',
				async: false,
				success: function(dados){
					plota_grafico_horario(dados);
				}
			});
		}

		function plota_grafico_horario(dados) {

			var horas = dados.horarios.length;
			var fonte = dados.estacao.idRede.sigla;
			var idRede = dados.estacao.idRede.idRede;
			var area = geral[0][0];
			var titulo = geral[area][3];
			var medida = geral[area][4];

			//INEA: de local para GMT
			var fuso = todayLocal.getTimezoneOffset()/60;
			var fuso = 0;
			var i = dados.datas.length-1;
			var stopHr = dados.acumulados[0].length-1;
			for (var j=stopHr; j>=fuso; j--) {
				//console.log("StopHr " + j + "Data Inicial - "+dados.datas[i]+" - "+dados.horarios[j]+" : "+dados.acumulados[i][j]);
				var hrLocal = dados.horarios[j-fuso].split("h");
				if (hrLocal[0] > (23-fuso)){
					a = i - 1;
				} else {
					a = i;
				}
				dados.acumulados[i][j] = dados.acumulados[a][j-fuso];
				//console.log("StopHr " + j + "Data Final - "+dados.datas[i]+" - "+dados.horarios[j]+" : "+dados.acumulados[i][j]);
				if (dados.horarios[j] == '0h') {
					i--;
				}
			}

			// Return if (Dados == NULL)
			var a = 0;
			var iniI = 0;
			var iniJ = fuso;
			for (var j=iniJ; j<stopHr; j++) {
				for (var i=iniI; i<dados.acumulados.length; i++) {
					if (dados.acumulados[i][j] != null) {
						a = 1;
					}
				}
			}
			if (a == 0){
				$("#container"+area+"").text("Sem Dados ("+titulo+" Acumulada em "+(horas-fuso)+"h)");
				return;
			}

			// SERIES E DIAS (grafico pie)

				// Calcula Acumulado das Series e Dias: Elimina os valores nulos
					var arrHorarios = [];
					var arrValores = [];
					for (var z=0; z<dados.acumulados.length; z++){arrValores[z] = [];}
					var arrAcumulado = [];
					var arrTotalDia = [];
					var acc = 0;
					var h = 0;
					for (var j=iniJ; j<=stopHr; j++) {
						var acumuladoSeries = 0;
						arrHorarios[h] = dados.horarios[j];
						arrAcumulado[h] = null;
						for (var i=iniI; i<dados.acumulados.length; i++) {
							if (j == iniJ) { arrTotalDia[i] = 0; }
							//console.log("Horario "+dados.datas[i]+" - "+dados.horarios[j]+" : "+dados.acumulados[i][j]);
							if (dados.acumulados[i][j] != null) {
								acumuladoSeries += dados.acumulados[i][j];
								arrTotalDia[i] += dados.acumulados[i][j];
								arrValores[i][h] = roundNumber(dados.acumulados[i][j],2);

								lastData = dados.datas[i];
								lastHora = arrHorarios[h];
								geral[area][5] = arrValores[i][h]; // geral[area][5]: valor_atual

								acc +=acumuladoSeries;
								arrAcumulado[h] = roundNumber(acc,2);
							} else {
								arrValores[i][h] = null;
							}
						}
						h++;
					}

				// Constroi: Series
					a = 0;
					var se = new Array();
					for (var i=iniI; i<dados.datas.length; i++) {
						s = {
							type: 'column',
							name: dados.datas[i],
							data: arrValores[i],
							color: cor[a]
						};
						se.push(s);
						a++;
					}

				// Add Se[]: Acumulado Serie
					se.push({
						type: 'spline',
						name: 'Acumulado',
						data: arrAcumulado,
						color: cor[a]
					})

				// Array Dia[]: Acumulado Dia
					a = 0;
					var dia = new Array();
					for(var i=iniI; i<dados.datas.length; i++) {
						d = {
							name: dados.datas[i],
							y: roundNumber(arrTotalDia[i],2),
							color: cor[a]
						};
						dia.push(d);
						a++;
					}

				// Add Se[]: Grafico Pie - Dia
					se.push({
						type: 'pie',
						name: 'Total consumption',
						data: dia,
						center: [60, 310],
						size: 40,
						showInLegend: false,
						dataLabels: {
							enabled: false
						}
					})

			// PRINCIPAL - chartHorario
				charts.chartHorario = new Highcharts.Chart({
					chart: {
						renderTo: 'container'+area+'',
						zoomType: 'xy',
						type: 'area',
						spacingBottom: 40
					},
					title: {
						text: titulo+' Acumulada em '+(horas-fuso)+'h | Estação: '+dados.estacao.nome+' ('+dados.estacao.codEstacao+')'
					},
					subtitle: {
						text: 'Fonte: '+fonte+' | Elaboração: CEMADEN',
						floating: true,
						align: 'right',
						verticalAlign: 'bottom',
						y: 15
					},
					yAxis: {
						title: {
							text: '['+medida+']'
						}
					},
					xAxis: {
						categories: arrHorarios,
						labels: {
							rotation: -45,
							align: 'right'
						}
					},
					tooltip: {
						enable:true,
						formatter: function() {
							var s;
							if (this.point.name) { // the pie chart
								s = ''+
									this.point.name +': '+ this.y +' '+medida+'';
							} else {
								s = ''+
									this.series.name +' às '+this.x  +'00: '+ this.y +' '+medida+'';
							}
							return s;
						}
					},
					plotOptions: {
						line: {
							dataLabels: {
								enabled: true
							},
							enableMouseTracking: true
						}
					},
					credits: {
						enabled: false
					},
					labels: {
						items: [{
							html: 'Total de Precipitação',
							style: {
								left: '20px',
								top: '290px',
								color: 'black'
							}
						}]
					},
					series: se,
					exporting: {
						enabled: true,
						width: 2800,
					}
				});

			// Cabecalho - Captura Dados
				if (dados.estacao.idMunicipio!= null) {
					estacao = dados.estacao.nome;
					codEstacao = dados.estacao.codEstacao;
					tipoEstacao = dados.estacao.idTipoestacao.descricao;
					cidade = dados.estacao.idMunicipio.cidade;
                                        parent.nomeCidade = cidade;
					estado = dados.estacao.idMunicipio.uf;
				}
			//FIM: Cabecalho - Captura Dados

			//*-- Cabecalho - Imprime	(TEMPORARIO, default: grafico de linhas)

				//div_titulo
					var tit = 'Condições Atuais | Estação: '+estacao+' ('+codEstacao+') | Tipo: '+tipoEstacao+' | Município: '+cidade+'/'+estado+' | Fonte: '+fonte+' | Atualização: '+lastData+' '+lastHora+' UTC';
					$( '#div_titulo' ).text( tit );
				//FIM: div_titulo

				// div_subtitulo
					var subtitulo = 'Sem Dados Atuais';
					for (i=0; i<geral.length; i++){
						if (geral[i][5] != null){
							if (subtitulo == 'Sem Dados Atuais'){
								subtitulo = geral[i][3]+': '+geral[i][5]+' '+geral[i][4]+'';
							}
						}
					}
					$( '#div_subtitulo' ).text( subtitulo );
				//FIM: div_subtitulo

			//*-- FIM: Cabecalho - Imprime
		}

		/******************************************************************** FIM - GRAFICO HORARIO ****************************************************************************/


		/********************************************************************** GRAFICO DIARIO ******************************************************************************/

				function cria_grafico_diario(dias){

			//console.log("dias "+dias);

			var url = path+geral[0][2]+"/"+idEstacao+"/"+dias;
			//console.log(url);

			$.ajax({
				url: url,
				context: this,
				crossDomain: true,
				// cache: false,
				dataType: 'json',
				async: false,
				success: function(dados){
				plota_grafico_diario(dados);
				}
			});
		}

		function plota_grafico_diario(dados) {

			var fonte = dados.estacao.idRede.sigla;
			var idRede = dados.estacao.idRede.idRede;
			var dias = (dados.datas.length)-1; //(nao soma a dta a ser convertida: ANA e nem a dta atual)
			var area = geral[1][0];
			var medida = geral[area][4];
			var titulo = geral[area][3];

			// Definindo: stopDia
			for (var i=0; i<=dados.datas.length; i++){
 				var month = todayGMT.getMonth()+1;
				var monthWithZero = month;
				if(month<10){
					monthWithZero = "0"+month;
 				}
				var day = todayGMT.getDate();
				var dayWithZero = day;
				if(day<10){
					dayWithZero = "0"+day;
				}
				var dta_end_with_zero = (dayWithZero)+"/"+(monthWithZero)+"/"+todayGMT.getFullYear();
				var dta_end = todayGMT.getDate()+"/"+(todayGMT.getMonth()+1)+"/"+todayGMT.getFullYear();
				if (dta_end == dados.datas[i] || dta_end_with_zero == dados.datas[i]){
					var stopDia = i;
				}
			}
			// FIM: stopDia

			//ANA: de local para GMT
			var iniI = 0;
			var iniJ = 0;
			var fuso = todayLocal.getTimezoneOffset()/60;
			var fuso = 0;
			if (dados.horarios[0].split("h")[0] >= 21){
				var iniI = 1;
			}
			var stopHr = (dados.acumulados[0].length-1)-fuso;
			if (idRede == 1) {
				var i = 0;
				for (var j=0; j<stopHr; j++) {
					var dtaLocal = dados.datas[i].split("/");
					//console.log("valor "+dados.acumulados[i][j]);
					var hrLocal = dados.horarios[j].split("h");
					var dateGMT = new Date(dtaLocal[2],(dtaLocal[1]-1),dtaLocal[0],hrLocal[0],0,0); //new Date(year, month, day, hours, minutes, seconds, milliseconds)
					//console.log("dateGMT antes "+dateGMT);
					var fuso = dateGMT.getTimezoneOffset()/60; //converte fuso horario p/ hrs
					dateGMT.setHours(dateGMT.getHours()+fuso);// converte data-hr p/ GMT
					//console.log("dateGMT depois "+dateGMT);
					dados.horarios[j] = dateGMT.getHours()+"h";	//substitui hr p/ GMT
					if (hrLocal[0] > dateGMT.getHours()){
						if (!dados.datas[i+1]){
							dados.datas[i+1] = dateGMT.getDate()+"/"+(dateGMT.getMonth()+1)+"/"+dateGMT.getFullYear(); //add nova dta GMT
							for(a=0; a<j; a++){ //add novo array (zera)
								dados.acumulados[i+1][a] = null;
							}
						}
						dados.acumulados[i+1][j] = dados.acumulados[i][j];
						dados.acumulados[i][j] = null;
						if (hrLocal[0] == 23){ i++; }
					}
				}
			} else {
				var iniJ = 3;
				for (var j=0; j<fuso; j++){ //anula as 3hrs a mais, add por causa da ANA
					dados.acumulados[0][j] = null;
				}
				stopHr += fuso; // prolonga o termino, ja q ira pular as 3 hrs add por causa da ANA
			}

			// Return if (Dados == NULL)
			var a = 0;
			for (var i=0; i<stopDia; i++) {
				for (var j=0; j<stopHr; j++) {
					if (dados.acumulados[i][j] != null) {
						a = 1;
					}
				}
			}
			if (a == 0){
				$("#container"+area+"").text("Sem Dados ("+titulo+" Acumulada em "+dias+" dias)");
				return;
			}

			// SERIES E DIAS (grafico pie)

				// Calcula Acumulado das Series e Dias: Elimina os valores nulos
					var arrDias = [];
					var arrValores = [];
					var arrAcumulado = [];
					var acumulados = 0;
					var d = 0;
					for (var i=iniI; i<stopDia; i++) {
						arrDias[d] = dados.datas[i];
						arrValores[d] = null;
						arrAcumulado[d] = null;
						var accValores = 0;
						for (var j=iniJ; j<stopHr; j++) {
							if (dados.horarios[j] == "23h" && dados.horarios[j+1] == "23h") j++;
							//console.log("Diario - "+dados.datas[i]+" - "+dados.horarios[j]+" : "+dados.acumulados[i][j]);
							if (dados.acumulados[i][j] != null) {
								accValores += dados.acumulados[i][j];
								acumulados += dados.acumulados[i][j];
								arrValores[d] = roundNumber(accValores,2);
								arrAcumulado[d] = roundNumber(acumulados,2);
							}
							if (dados.horarios[j] == '23h'){
								iniJ = j + 1;
								j=stopHr;
							}
						}
						d++;
					}
					//console.log("arrDias "+arrDias);
					//console.log("arrValores "+arrValores);
					//console.log("arrAcumulado "+arrAcumulado);

			// PRINCIPAL - chartDiario
				charts.chartDiario = new Highcharts.Chart({
					chart: {
						renderTo: 'container1',
						zoomType: 'xy',
						type: 'area',
						spacingBottom: 40
					},
					title: {
						text: titulo+' Acumulada em '+dias+' dias '+'| Estação: '+dados.estacao.nome+' ('+dados.estacao.codEstacao+')'
					},
					subtitle: {
						text: 'Fonte: '+fonte+" | Elaboração: CEMADEN",
						floating: true,
						align: 'right',
						verticalAlign: 'bottom',
						y: 15
					},
					yAxis: {
						title: {
							text: '['+medida+']'
						}
					},
					xAxis: {
						categories: arrDias,
						labels: {
							rotation: -45,
							align: 'right'
						}
					},
					tooltip: {
						formatter: function() {
							var s;
							if (this.point.name) { // the pie chart
								s = ''+
									this.point.name +': '+ this.y +' '+medida+'';
							} else {
								s = ''+
									this.series.name +' '+this.x  +': '+ this.y +' '+medida+'';
							}
							return s;
						}
					},
					series: [{
						type: 'column',
						name: 'Diária',
						data: arrValores
					},
					{
						type: 'spline',
						name: 'Acumulado',
						data: arrAcumulado
					}],
					exporting: {
						enabled: true,
						width: 2800,
						area: area
					}
				});
			// FIM: PRINCIPAL - chartDiario
		}

		/******************************************************************** FIM - GRAFICO DIARIO ****************************************************************************/

	function Grafico(id,medida,titulo,fonte,categoria,nome1,valores1,nome2,valores2) {
		$(function () {
			var chart;
			$(document).ready(function() {
				var pcdData = [];
				var pcdAcumData = [];
				for (var key in valores1) {
					pcdData.push([Date.parse(categoria[key].replace("h",":")+" UTC"),parseFloat(valores1[key].toFixed(1))]);
					pcdAcumData.push([Date.parse(categoria[key].replace("h",":")+" UTC"),parseFloat(valores2[key].toFixed(1))]);
				}
				chart = new Highcharts.Chart({
					chart: {
						renderTo: 'container'+id,
						zoomType: 'xy',
						spacingBottom: 40
					},
					title: {
						text: titulo
					},
					subtitle: {
						text: 'Fonte: '+fonte+" | Elaboração: CEMADEN",
						floating: true,
						align: 'right',
						verticalAlign: 'bottom',
						y: 15
					},
					xAxis: {
						type: 'datetime',
						dateTimeLabelFormats: {
							second: '%H:%M',
							minute: '%H:%M',
							hour: '%H:%M',
							day: '%H:%M',
							week: '%H:%M',
							month: '%H:%M',
							year: '%H:%M'
						},
						labels: {
							rotation: -45,
							align: 'right'
						},
						tickInterval: 3600 * 100
					},
					yAxis: {
						title: {
							text: '['+medida+']'
						},
						labels: {
							formatter: function() {
								return this.value;
							}
						}
					},
					tooltip: {
						formatter: function() {
							var s;
							if (this.point.name) { // the pie chart
								s = ''+
									this.point.name +': '+ this.y +' '+medida+'';
							} else {
								s = ''+
									this.series.name +' às '+ ConvertHighchartData(this.x) +': '+ this.y +' '+medida+'';
							}
							return s;
						}
					},
					plotOptions: {
						area: {
							fillOpacity: 0.5
						}
					},
					credits: {
						enabled: false
					},
					series: [{
						name: nome1,
						type: 'column',
						data: pcdData
					},{
						name: nome2,
						type: 'spline',
						data: pcdAcumData
					}]
				});
			});
		});
	}

	function ConvertHighchartData (data) {
		return Highcharts.dateFormat('%e/%m/%Y %H:%M', data)
	}

	function Grafico2(id,medida,titulo,fonte,categoria,nome1,valores1,nome2,valores2,nome3,valores3) {
		$(function () {
			var chart;
			$(document).ready(function() {
				chart = new Highcharts.Chart({
					chart: {
						renderTo: 'container'+id,
						zoomType: 'xy',
						spacingBottom: 40
					},
					title: {
						text: titulo
					},
					subtitle: {
						text: 'Fonte: '+fonte+" | Elaboração: CEMADEN",
						floating: true,
						align: 'right',
						verticalAlign: 'bottom',
						y: 15
					},
					xAxis: {
						categories: categoria,
						labels: {
							rotation: -45,
							align: 'right'
						}
					},
					yAxis: {
						title: {
							text: '[mm]'
						},
						labels: {
							formatter: function() {
								return this.value;
							}
						}
					},
					tooltip: {
						formatter: function() {
							var s;
							if (this.point.name) { // the pie chart
								s = ''+
									this.point.name +': '+ this.y +' '+medida+'';
							} else {
								s = ''+
									this.series.name +' às '+this.x  +': '+ this.y +' '+medida+'';
							}
							return s;
						}
					},
					plotOptions: {
						area: {
							fillOpacity: 0.5
						}
					},
					credits: {
						enabled: false
					},
					series: [{
						name: nome1,
						type: 'column',
						data: valores1
					},{
						name: nome2,
						type: 'column',
						data: valores2
					},{
						name: nome3,
						type: 'spline',
						data: valores3
					}]
				});
			});
		});
	}

		/********************************************************************** GRAFICO PERMANENCIA ******************************************************************************/

		function cria_grafico_permanencia(){
			$(document).ready(function() {

				var url = "http://resources.cemaden.gov.br/graficos/json_inea/"+idEstacao+".json";

				$.ajax({
					url: url,
					context: this,
					crossDomain: true,
					// cache: false,
					dataType: 'json',
					async: false,
					success: function(dados){
						plota_grafico_permanencia(dados);
					}
				});
			});
		}

		function plota_grafico_permanencia(dados) {

			var niveis = dados.nivel_inea_ind.length;
			if (niveis == 0) {
                $("#container").text("Sem Dados ("+titulo+")");
                return;
			}

			var fonte = dados.estacao.idRede.sigla;
			var titulo = 'Curva de Permanência';
			var medida = 'Nível [m]';
			//var limiar = 0.6; //valor a ser capturado do JSON
			var limiar = dados.estacao.limiar;

			var arrPermanencia = new Array();
			var arrNivel = new Array();
			var arrDatas = new Array();
			var maxNivel = 0;
			for (var i=0; i<niveis; i++) {
				arrPermanencia[i] = Highcharts.numberFormat(dados.nivel_inea_ind[i]['p'], 4);
				arrNivel[i] = Math.round(dados.nivel_inea_ind[i]['nivel']*Math.pow(10,2))/Math.pow(10,2);
				if (maxNivel < arrNivel[i]) maxNivel = arrNivel[i]; //captura valor maximo do eixo y
				var dta = dados.nivel_inea_ind[i]['datahora'].split("-");
				var cc = dta[2].split(" ");
				arrDatas[arrNivel[i]] = cc[0]+"/"+dta[1]+"/"+dta[0]+" "+cc[1];
			}

			if (maxNivel < limiar) maxNivel = limiar;

			// PRINCIPAL
			var chart = new Highcharts.Chart({
            chart: {
                renderTo: 'container',
                zoomType: 'x',
                spacingRight: 20
            },
            title: {
                text: titulo+' | Estação: '+dados.estacao.nome+' ('+dados.estacao.codEstacao+')'
            },
			subtitle: {
				text: 'Fonte: '+fonte+" | Elaboração: CEMADEN",
				floating: true,
				align: 'right',
				verticalAlign: 'bottom',
				y: 15
			},
            xAxis: {
				labels: {
                    formatter: function() {
                        return arrPermanencia[this.value];
                    }
                }
            },
            yAxis: {
                title: {
                    text: medida
                },
                min: 0,
				max: maxNivel,
                startOnTick: false,
                showFirstLabel: false
            },
			tooltip: {
				formatter: function() {
					return 'Data: ' + arrDatas[this.y] + '<br>'+titulo+': '+ arrPermanencia[this.x]  +'<br>Nível: '+ this.y ;
				}
			},
            legend: {
                enabled: true,
            },
            plotOptions: {
                area: {
						fillColor: {
                        linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1},
                        stops: [
                            [0, Highcharts.getOptions().colors[0]],
                            [1, 'rgba(2,0,0,0)']
                        ]
                    },
                    lineWidth: 1,
                    marker: {
                        enabled: false,
                        states: {
                            hover: {
                                enabled: true,
                                radius: 5
                            }
                        }
                    },
                    shadow: false,
                    states: {
                        hover: {
                            lineWidth: 1
                        }
                    }
                }
            },
			credits: {
				enabled: false
			},
            series: [{
                type: 'area',
                name: titulo+' [%]',
                data: arrNivel
            },{
                type: 'line',
                name: 'Limiar',
                data: [[0, limiar], [niveis, limiar]],
                marker: {
                    enabled: false
                },
                states: {
                    hover: {
                        lineWidth: 0
                    }
                },
                enableMouseTracking: false
			}]
        });

		}

		/******************************************************************** FIM - GRAFICO PERMANENCIA ****************************************************************************/

	</script>

</head>
<body>
	<div>
		<!--
		<div class="topo">
			<div style="padding-left:10px; padding-top:3px; float:left"><img src="img/logo.png"></div>
			<div style="padding-left:12px; padding-top:12px; float:left">SALVAR 2.0 - Gr&aacute;ficos para PCDs</div>
			<script type='text/javascript'>
				//document.write("<input type=\"button\" class=\"btntop\" value=\"Editar\" style=\"float:right; margin-top:10px; margin-right:20px;\" onclick=\"window.open('graficoEditINEA.html?idpcd="+idEstacao+"','CEMADEN_Compor_Grafico','resizable=yes,scrollbars=yes,top=10,left=10,width=1530,height=660');\"> ");
			</script>
		</div>
		-->
        <div class="conteudo">
         <div class="panel panel-default">
          <div class="panel-heading"><b>Gráficos</b></div>
              <div class="panel-body">

			<div class="mapa" style="width: 100%; margin: auto auto">
				<div class="mapab">
					<div  id="div_titulo" style="text-align:center; font-weight:bold;" class="alert alert-info"></div>
					<div class="subtitulo" id="div_subtitulo"></div>
					<div class="linhat"></div>
					<br/><br/>
					<div id="container2" class="div1"></div>
					<br/><br/>
					<div style="margin-bottom:10px; margin-top:425px;"></div>
					<div>
                    	<form class="form"><p> Horas:
							<select id="selhorario" style="width:140px;">
								<script type='text/javascript'>
									for (i=12; i<=96; i+=6){
										if ((i+fuso) == select_hr){
											document.write("<option value="+(i+fuso)+" selected='selected'>"+i+"</option>");
										} else {
											document.write("<option value="+(i+fuso)+">"+i+"</option>");
										}
									}
								</script>
                            </select>
                        </p></form>
					</div>
					<div id="container0" class="div1" ></div>
					<br/><br/>
					<div style="margin-bottom:10px; margin-top:425px;"> Dias:
						<select id="seldiario" style="width:140px;">
							<script type='text/javascript'>
								var a = 0;
								for (i=168; i<=840; i+=168){
									var tmpDia = todayLocal.getHours() + i; //default
									if (tmpDia == select_dia){
										document.write("<option value="+tmpDia+" selected='selected'>"+roundNumber((i/24),0)+"</option>");
									} else {
										document.write("<option value="+tmpDia+">"+roundNumber((i/24),0)+"</option>");
									}
								}
							</script>
						</select>
					</div>
					<div id="container1" class="div1" ></div>
					<br>
									</div>
			</div>
		</div>

		</div> </div> </div>
    	<div class="clearb"></div>
        <!--<div class="rodape">Cemaden 2012 - Hor&aacute;rio GMT</div>-->
	</div>
</body>
</html>
