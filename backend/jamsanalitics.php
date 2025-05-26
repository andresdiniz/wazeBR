<?php
/**
 * Traffic Jam Analysis Dashboard - Dados Consolidados
 * Versão 2.0 com melhorias e novas funcionalidades
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/php_errors.log');

require_once './config/configbd.php';
require_once './vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TrafficJamAnalyzer {
    private $pdo;
    private $validPartnerIds = [1, 2, 3, 99]; // Exemplo de IDs válidos

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Método auxiliar para execução de queries
     */
    private function executeQuery(string $query, array $params = [], bool $fetchAll = true) {
        try {
            $stmt = $this->pdo->prepare($query);
            
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            
            $stmt->execute();
            return $fetchAll ? $stmt->fetchAll(PDO::FETCH_ASSOC) : $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Erro na query: $query | Params: " . json_encode($params));
            throw new Exception("Erro de banco de dados: " . $e->getMessage());
        }
    }

    private function validatePartnerId($id_parceiro) {
        if (!in_array($id_parceiro, $this->validPartnerIds)) {
            throw new InvalidArgumentException("ID de parceiro inválido");
        }
    }

    private function addPartnerFilter(&$query, $id_parceiro) {
        if ($id_parceiro != 99) {
            $query .= (stripos($query, 'WHERE') !== false) ? ' AND ' : ' WHERE ';
            $query .= 'id_parceiro = :id_parceiro';
            return true;
        }
        return false;
    }

    public function getAllData($id_parceiro) {
        $this->validatePartnerId($id_parceiro);
        
        return [
            'resumo' => $this->getResumo($id_parceiro),
            'horario' => $this->getDistribuicaoHoraria($id_parceiro),
            'semanal' => $this->getPadraoSemanal($id_parceiro),
            'mensal' => $this->getTendenciaMensal($id_parceiro),
            'cidades' => $this->getTopCidades($id_parceiro),
            'ruas' => $this->getTopRuas($id_parceiro),
            'niveis' => $this->getNiveisCongestionamento($id_parceiro),
            'tipos_via' => $this->getTiposVia($id_parceiro),
            'heatmap' => $this->getHeatmap($id_parceiro),
            'segmentos' => $this->getTopSegmentos($id_parceiro),
            'length_delay' => $this->getLengthDelayData($id_parceiro),
            'dia_hora' => $this->getDiaHoraData($id_parceiro),
            'km_por_hora' => $this->getKmPorHora($id_parceiro),
            'km_por_dia_semana' => $this->getKmPorDiaSemana($id_parceiro),
            'media_km_por_hora' => $this->getMediaKmPorHora($id_parceiro),
            'media_km_por_dia_semana' => $this->getMediaKmPorDiaSemana($id_parceiro),
            'novas' => [
                'eficiencia_vias' => $this->getEficienciaPorTipoVia($id_parceiro),
                'tendencia_diaria' => $this->getTendenciaDiaria($id_parceiro),
                'previsao' => $this->getPrevisaoCongestionamentos($id_parceiro)
            ]
        ];
    }

    // Funções originais atualizadas
    private function getResumo($id_parceiro) {
        $query = "SELECT COUNT(*) as total, AVG(delay) as atraso_medio,
                 MAX(delay) as max_atraso, AVG(length) as comprimento_medio
                FROM jams";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        
        return $this->executeQuery($query, $params, false);
    }

    private function getDistribuicaoHoraria($id_parceiro) {
        $query = "SELECT HOUR(date_received) as hora, COUNT(*) as total
                FROM jams";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY hora ORDER BY hora";
        
        return $this->executeQuery($query, $params);
    }

    private function getPadraoSemanal($id_parceiro) {
        $query = "SELECT DAYNAME(date_received) as dia, COUNT(*) as total
                FROM jams";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY dia ORDER BY DAYOFWEEK(date_received)";
        
        return $this->executeQuery($query, $params);
    }

    private function getTendenciaMensal($id_parceiro) {
        $query = "SELECT DATE_FORMAT(date_received, '%Y-%m') as mes, COUNT(*) as total
                FROM jams";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY mes ORDER BY mes";
        
        return $this->executeQuery($query, $params);
    }

    private function getTopCidades($id_parceiro) {
        $query = "SELECT city as cidade, COUNT(*) as total
                FROM jams WHERE city IS NOT NULL";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY cidade ORDER BY total DESC LIMIT 10";
        
        return $this->executeQuery($query, $params);
    }

    private function getTopRuas($id_parceiro) {
        $query = "SELECT street AS rua, COUNT(*) AS total,
                ROUND(AVG(delay)/60,1) AS atraso_medio_min,
                ROUND(AVG(length),1) AS comprimento_medio_m
                FROM jams WHERE street IS NOT NULL";
    
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY rua ORDER BY total DESC LIMIT 10";
    
        return $this->executeQuery($query, $params);
    }

    private function getNiveisCongestionamento($id_parceiro) {
        $query = "SELECT level as nivel, COUNT(*) as total FROM jams";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY nivel ORDER BY nivel";
        
        return $this->executeQuery($query, $params);
    }

    private function getTiposVia($id_parceiro) {
        $query = "SELECT roadType as tipo, COUNT(*) as total FROM jams";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY tipo ORDER BY total DESC";
        
        return $this->executeQuery($query, $params);
    }

    private function getHeatmap($id_parceiro) {
        $query = "SELECT j.uuid, jl.x, jl.y
                FROM jams j
                JOIN jam_lines jl ON j.uuid = jl.jam_uuid";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " ORDER BY j.date_received DESC LIMIT 1000";
        
        return $this->executeQuery($query, $params);
    }

    private function getTopSegmentos($id_parceiro) {
        $query = "SELECT js.ID_segment AS segmento, j.street AS rua,
                COUNT(*) AS total, ROUND(AVG(j.delay)/60,1) AS atraso_medio_min
                FROM jam_segments js
                JOIN jams j ON js.jam_uuid = j.uuid";
    
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY js.ID_segment, j.street ORDER BY total DESC LIMIT 10";
    
        return $this->executeQuery($query, $params);
    }

    // Funções otimizadas
    private function getKmPorHora($id_parceiro) {
        return $this->getDadosHorarios($id_parceiro, 'SUM');
    }

    private function getMediaKmPorHora($id_parceiro) {
        return $this->getDadosHorarios($id_parceiro, 'AVG');
    }

    private function getDadosHorarios($id_parceiro, $operation) {
        $query = "SELECT HOUR(date_received) AS hora,
                ROUND($operation(length)/1000,2) AS total_km
                FROM jams";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY hora ORDER BY hora";
        
        return $this->executeQuery($query, $params);
    }

    // Novas funções implementadas
    public function getEficienciaPorTipoVia($id_parceiro) {
        $query = "SELECT roadType AS tipo_via,
                COUNT(*) AS total_ocorrencias,
                ROUND(AVG(speedKMH),1) AS velocidade_media,
                ROUND(AVG(delay)/60,1) AS atraso_medio_min
                FROM jams";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY roadType ORDER BY total_ocorrencias DESC";
        
        return $this->executeQuery($query, $params);
    }

    public function getTendenciaDiaria($id_parceiro) {
        $query = "SELECT DATE(date_received) AS data,
                COUNT(*) AS total,
                ROUND(AVG(delay)/60,1) AS atraso_medio
                FROM jams
                WHERE date_received >= CURDATE() - INTERVAL 30 DAY";
        
        $params = [];
        if ($this->addPartnerFilter($query, $id_parceiro)) {
            $params[':id_parceiro'] = $id_parceiro;
        }
        $query .= " GROUP BY data ORDER BY data DESC";
        
        return $this->executeQuery($query, $params);
    }

    public function getPrevisaoCongestionamentos($id_parceiro) {
        $historico = $this->getTendenciaMensal($id_parceiro);
        
        $dadosTreino = [];
        foreach ($historico as $mes) {
            $dadosTreino[] = [
                'x' => (int)str_replace('-', '', $mes['mes']),
                'y' => $mes['total']
            ];
        }
        
        $regressao = $this->simpleLinearRegression($dadosTreino);
        $ultimoMes = end($historico)['mes'];
        $proximoMes = date('Y-m', strtotime($ultimoMes . ' +1 month'));
        $previsao = $regressao['a'] * (float)str_replace('-', '', $proximoMes) + $regressao['b'];
        
        return [
            'proximo_mes' => $proximoMes,
            'previsao' => round($previsao),
            'coeficiente' => $regressao['a']
        ];
    }

    private function simpleLinearRegression($data) {
        $n = count($data);
        $sumX = $sumY = $sumXY = $sumX2 = 0;

        foreach ($data as $point) {
            $sumX += $point['x'];
            $sumY += $point['y'];
            $sumXY += $point['x'] * $point['y'];
            $sumX2 += pow($point['x'], 2);
        }

        $a = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - pow($sumX, 2));
        $b = ($sumY - $a * $sumX) / $n;

        return ['a' => $a, 'b' => $b];
    }
}

// Processamento principal e renderização mantidos igual
try {
    session_start();
    $id_parceiro = $_SESSION['usuario_id_parceiro'];
    $pdo = Database::getConnection();
    
    $analyzer = new TrafficJamAnalyzer($pdo);
    $data = $analyzer->getAllData($id_parceiro);
    
    $data['meta'] = [
        'status' => 'sucesso',
        'tempo_execucao' => round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 2) . 's',
        'gerado_em' => date('d/m/Y H:i:s'),
        'parceiro_id' => $id_parceiro
    ];

} catch (Exception $e) {
    $data = [
        'meta' => [
            'status' => 'erro',
            'mensagem' => $e->getMessage()
        ]
    ];
}

$loader = new FilesystemLoader(__DIR__ . '/../frontend');
$twig = new Environment($loader);