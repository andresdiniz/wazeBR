<?php
/**
 * Traffic Jam Analysis Dashboard - Dados Consolidados
 */


// Nível de relatório de erros
error_reporting(E_ALL);

// Desativar exibição de erros na tela
ini_set('display_errors', 0);

// Ativar logs de erros
ini_set('log_errors', 1);

// Definir caminho do arquivo de log
ini_set('error_log', __DIR__.'php_errors.log');

require_once './config/configbd.php'; // Conexão ao banco de dados
require_once './vendor/autoload.php'; // Autoloader do Composer

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Configura o carregador do Twig para buscar templates na pasta "frontend"
$loader = new FilesystemLoader(__DIR__ . '/../frontend'); // Caminho para a pasta frontend
$twig = new Environment($loader);

class TrafficJamAnalyzer {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
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
            'dia_hora' => $this->getDiaHoraData($id_parceiro)
        ];
    }

    private function getResumo($id_parceiro) {
        $query = "SELECT 
                    COUNT(*) as total,
                    AVG(delay) as atraso_medio,
                    MAX(delay) as max_atraso,
                    AVG(length) as comprimento_medio
                FROM jams";
        
        $hasFilter = $this->addPartnerFilter($query, $id_parceiro);
        
        $stmt = $this->pdo->prepare($query);
        if ($hasFilter) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getDistribuicaoHoraria($id_parceiro) {
        $query = "SELECT 
                    HOUR(date_received) as hora,
                    COUNT(*) as total
                FROM jams";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " GROUP BY hora ORDER BY hora";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPadraoSemanal($id_parceiro) {
        $query = "SELECT 
                    DAYNAME(date_received) as dia,
                    COUNT(*) as total
                FROM jams";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " GROUP BY dia ORDER BY DAYOFWEEK(date_received)";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTendenciaMensal($id_parceiro) {
        $query = "SELECT 
                    DATE_FORMAT(date_received, '%Y-%m') as mes,
                    COUNT(*) as total
                FROM jams";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " GROUP BY mes ORDER BY mes";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTopCidades($id_parceiro) {
        $query = "SELECT 
                    city as cidade,
                    COUNT(*) as total
                FROM jams
                WHERE city IS NOT NULL";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " GROUP BY cidade ORDER BY total DESC LIMIT 10";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTopRuas($id_parceiro) {
        $query = "SELECT 
                    street AS rua,
                    COUNT(*) AS total,
                    ROUND(AVG(delay) / 60, 1) AS atraso_medio_min,
                    ROUND(AVG(length), 1) AS comprimento_medio_m,
                    ROUND(AVG(speedKMH), 1) AS velocidade_media_kmh,
                    ROUND(MAX(delay) / 60, 1) AS atraso_max_min
                FROM jams
                WHERE street IS NOT NULL";
    
        $this->addPartnerFilter($query, $id_parceiro);
    
        $query .= " GROUP BY rua ORDER BY total DESC LIMIT 10";
    
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) {
            $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        }
        $stmt->execute();
    
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
   public function getLengthDelayData($id_parceiro, int $limit = 100): array {
        try {
            $query = "SELECT uuid, length AS comprimento, delay AS atraso FROM jams";
            $conditions = [
                "length IS NOT NULL",
                "delay IS NOT NULL",
                "length > 0",
                "delay > 0"
            ];

            // Adiciona filtro de parceiro apenas se não for 99
            $usePartnerFilter = $id_parceiro != 99;
            if ($usePartnerFilter) {
                $conditions[] = "id_parceiro = :id_parceiro";
            }

            $query .= " WHERE " . implode(" AND ", $conditions);
            $query .= " ORDER BY date_received DESC LIMIT :limit";

            $stmt = $this->pdo->prepare($query);

            if ($usePartnerFilter) {
                $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("Erro SQL em getLengthDelayData: " . $e->getMessage());
            return [];
        }
    }




    private function getNiveisCongestionamento($id_parceiro) {
        $query = "SELECT 
                    level as nivel,
                    COUNT(*) as total
                FROM jams";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " GROUP BY nivel ORDER BY nivel";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTiposVia($id_parceiro) {
        $query = "SELECT 
                    roadType as tipo,
                    COUNT(*) as total
                FROM jams";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " GROUP BY tipo ORDER BY total DESC";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getHeatmap($id_parceiro) {
        $query = "SELECT 
                    j.uuid, jl.x, jl.y
                FROM jams j
                JOIN jam_lines jl ON j.uuid = jl.jam_uuid";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " ORDER BY j.date_received DESC LIMIT 1000";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTopSegmentos($id_parceiro) {
        $query = "SELECT 
                    js.ID_segment AS segmento,
                    j.street AS rua,
                    COUNT(*) AS total,
                    ROUND(AVG(j.delay) / 60, 1) AS atraso_medio_min,
                    ROUND(AVG(j.length), 1) AS comprimento_medio_m,
                    ROUND(MAX(j.delay) / 60, 1) AS atraso_max_min
                FROM jam_segments js
                JOIN jams j ON js.jam_uuid = j.uuid
                WHERE j.id_parceiro = :id_parceiro
                GROUP BY js.ID_segment, j.street
                ORDER BY total DESC
                LIMIT 10";
    
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getDiaHoraData($id_parceiro): array {
        $query = "SELECT 
                    DAYOFWEEK(date_received) - 1 AS dia,  /* 0=Domingo, 6=Sábado */
                    HOUR(date_received) AS hora,
                    COUNT(*) AS quantidade,
                    ROUND(AVG(level), 2) AS media_nivel,
                    ROUND(AVG(speedKMH), 2) AS media_velocidade,
                    ROUND(AVG(delay), 2) AS media_atraso
                FROM jams";

        $this->addPartnerFilter($query, $id_parceiro);
        
        $query .= " GROUP BY dia, hora ORDER BY dia, hora";

        try {
            $stmt = $this->pdo->prepare($query);
            if ($id_parceiro != 99) {
                $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Erro ao buscar dados dia/hora: " . $e->getMessage());
            return [];
        }
    }

        
}

// Processamento principal
try {
    $id_parceiro = $_SESSION['usuario_id_parceiro'];
    $pdo = Database::getConnection();
    
    $analyzer = new TrafficJamAnalyzer($pdo);
    $data = $analyzer->getAllData($id_parceiro);
    
    // Adiciona metadados
    $data['meta'] = [
        'status' => 'sucesso',
        'tempo_execucao' => round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 2) . 's',
        'gerado_em' => date('d/m/Y H:i:s'),
        'parceiro_id' => $id_parceiro
    ];

} catch (PDOException $e) {
    $data = [
        'meta' => [
            'status' => 'erro',
            'mensagem' => 'Falha na conexão com o banco: ' . $e->getMessage()
        ]
    ];
} catch (Exception $e) {
    $data = [
        'meta' => [
            'status' => 'erro',
            'mensagem' => 'Erro geral: ' . $e->getMessage()
        ]
    ];
}

// Renderização
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../frontend');
$twig = new \Twig\Environment($loader);

$data = array_merge($data, [
    'resumo' => $data['resumo'],    
    'horario' => $data['horario'],
    'semanal' => $data['semanal'],
    'mes' => $data['mensal'],
    'cidades' => $data['cidades'],
    'ruas' => $data['ruas'],
    'niveis' => $data['niveis'],
    'tipos_via' => $data['tipos_via'],
    'heatmap' => $data['heatmap'],
    'segmentos' => $data['segmentos'],
    'meta' => $data['meta'],
    'length_delay' => $data['length_delay'],
    'dia_hora' => $data['dia_hora']
]);