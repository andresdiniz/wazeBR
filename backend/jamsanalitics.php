<?php
/**
 * Traffic Jam Analysis Dashboard - Dados Consolidados
 */

session_start();

require_once './config/configbd.php';
require_once './vendor/autoload.php';

if (!isset($_SESSION['usuario_id_parceiro'])) {
    header('Location: login.php');
    exit;
}

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
            'segmentos' => $this->getTopSegmentos($id_parceiro)
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
                    street as rua,
                    COUNT(*) as total
                FROM jams
                WHERE street IS NOT NULL";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " GROUP BY rua ORDER BY total DESC LIMIT 10";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                    js.ID_segment as segmento,
                    COUNT(*) as total
                FROM jam_segments js
                JOIN jams j ON js.jam_uuid = j.uuid";
        
        $this->addPartnerFilter($query, $id_parceiro);
        $query .= " GROUP BY segmento ORDER BY total DESC LIMIT 10";
        
        $stmt = $this->pdo->prepare($query);
        if ($id_parceiro != 99) $stmt->bindParam(':id_parceiro', $id_parceiro, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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