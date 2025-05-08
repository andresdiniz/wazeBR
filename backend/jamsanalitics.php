<?php
/**
 * Traffic Jam Analysis
 * 
 * Este script reúne dados de análise de congestionamentos de trânsito
 * a partir das tabelas jams, jam_segments e jam_lines.
 * 
 * Assume que uma conexão PDO já está disponível.
 */

// Inicializa a conexão PDO (você deve fornecer esta parte)
// $pdo = new PDO('mysql:host=hostname;dbname=u335174317_wazeportal', 'username', 'password');

// Classe para análise de dados de congestionamento

require_once './config/configbd.php';
require_once './vendor/autoload.php';

if (!isset($_SESSION['usuario_id_parceiro'])) {
    header('Location: login.php');
    exit;
}

$id_parceiro = $_SESSION['usuario_id_parceiro'];

// Marcar o tempo de início
$startTime = microtime(true);

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../frontend');
$twig = new \Twig\Environment($loader);

    $pdo = Database::getConnection();

class TrafficJamAnalysis {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Realiza todas as análises e retorna os resultados
     * 
     * @return array Dados de análise de congestionamentos
     */
    public function getAllAnalytics() {
        $data = [
            'summary' => $this->getSummaryData(),
            'top_segments' => $this->getTopSegmentsWithJams(),
            'hourly_distribution' => $this->getHourlyDistribution(),
            'top_streets' => $this->getTopStreetsByDelay(),
            'congestion_by_level' => $this->getCongestionByLevel(),
            'recent_jams' => $this->getRecentJams(),
            'city_analysis' => $this->getJamsByCity(),
            'roadtype_analysis' => $this->getJamsByRoadType(),
            'delay_distribution' => $this->getDelayDistribution(),
            'length_vs_delay' => $this->getLengthVsDelay(),
            'weekly_pattern' => $this->getWeeklyPattern(),
            'monthly_trend' => $this->getMonthlyTrend()
        ];
        
        return $data;
    }
    
    /**
     * Obtém dados resumidos sobre congestionamentos
     */
    public function getSummaryData() {
        $query = "
            SELECT 
                COUNT(*) as total_jams,
                AVG(delay) as avg_delay,
                MAX(delay) as max_delay,
                AVG(length) as avg_length,
                AVG(speedKMH) as avg_speed,
                COUNT(DISTINCT street) as unique_streets,
                COUNT(DISTINCT city) as unique_cities
            FROM jams
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém os segmentos com mais congestionamentos
     */
    public function getTopSegmentsWithJams() {
        $query = "
            SELECT 
                js.ID_segment,
                COUNT(*) as jam_count,
                AVG(j.delay) as avg_delay,
                AVG(j.length) as avg_length,
                MAX(j.delay) as max_delay,
                MIN(j.delay) as min_delay
            FROM jam_segments js
            JOIN jams j ON js.jam_uuid = j.uuid
            GROUP BY js.ID_segment
            ORDER BY jam_count DESC
            LIMIT 20
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém a distribuição de congestionamentos por hora do dia
     */
    public function getHourlyDistribution() {
        $query = "
            SELECT 
                HOUR(date_received) as hour_of_day,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay,
                AVG(speedKMH) as avg_speed
            FROM jams
            GROUP BY hour_of_day
            ORDER BY hour_of_day
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém as ruas com maior tempo médio de atraso
     */
    public function getTopStreetsByDelay() {
        $query = "
            SELECT 
                street,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay,
                AVG(length) as avg_length,
                MAX(delay) as max_delay
            FROM jams
            WHERE street IS NOT NULL AND street != ''
            GROUP BY street
            HAVING jam_count > 5
            ORDER BY avg_delay DESC
            LIMIT 20
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém análise de congestionamentos por nível
     */
    public function getCongestionByLevel() {
        $query = "
            SELECT 
                level,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay,
                AVG(speedKMH) as avg_speed,
                AVG(length) as avg_length
            FROM jams
            GROUP BY level
            ORDER BY level
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém congestionamentos recentes
     */
    public function getRecentJams() {
        $query = "
            SELECT 
                uuid,
                street,
                city,
                level,
                delay,
                length,
                speedKMH,
                date_received
            FROM jams
            ORDER BY date_received DESC
            LIMIT 50
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém análise de congestionamentos por cidade
     */
    public function getJamsByCity() {
        $query = "
            SELECT 
                city,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay,
                AVG(speedKMH) as avg_speed
            FROM jams
            WHERE city IS NOT NULL AND city != ''
            GROUP BY city
            ORDER BY jam_count DESC
            LIMIT 20
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém análise de congestionamentos por tipo de via
     */
    public function getJamsByRoadType() {
        $query = "
            SELECT 
                roadType,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay,
                AVG(speedKMH) as avg_speed,
                AVG(length) as avg_length
            FROM jams
            GROUP BY roadType
            ORDER BY roadType
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém distribuição de atrasos em intervalos
     */
    public function getDelayDistribution() {
        $query = "
            SELECT 
                CASE
                    WHEN delay < 60 THEN 'Menos de 1 min'
                    WHEN delay < 300 THEN '1-5 min'
                    WHEN delay < 600 THEN '5-10 min'
                    WHEN delay < 1200 THEN '10-20 min'
                    WHEN delay < 1800 THEN '20-30 min'
                    ELSE 'Mais de 30 min'
                END as delay_range,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay
            FROM jams
            GROUP BY delay_range
            ORDER BY min(delay)
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém relação entre comprimento do congestionamento e atraso
     */
    public function getLengthVsDelay() {
        $query = "
            SELECT 
                CASE
                    WHEN length < 100 THEN '0-100m'
                    WHEN length < 500 THEN '100-500m'
                    WHEN length < 1000 THEN '500-1000m'
                    WHEN length < 2000 THEN '1-2km'
                    WHEN length < 5000 THEN '2-5km'
                    ELSE 'Mais de 5km'
                END as length_range,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay,
                AVG(speedKMH) as avg_speed
            FROM jams
            GROUP BY length_range
            ORDER BY min(length)
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém padrão semanal de congestionamentos
     */
    public function getWeeklyPattern() {
        $query = "
            SELECT 
                DAYNAME(date_received) as day_of_week,
                DAYOFWEEK(date_received) as day_num,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay,
                AVG(length) as avg_length
            FROM jams
            GROUP BY day_of_week, day_num
            ORDER BY day_num
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtém tendência mensal de congestionamentos
     */
    public function getMonthlyTrend() {
        $query = "
            SELECT 
                DATE_FORMAT(date_received, '%Y-%m') as month,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay,
                AVG(length) as avg_length
            FROM jams
            GROUP BY month
            ORDER BY month
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Método adicional: análise específica de conversões (turnType)
     */
    public function getTurnTypeAnalysis() {
        $query = "
            SELECT 
                turnType,
                COUNT(*) as jam_count,
                AVG(delay) as avg_delay
            FROM jams
            WHERE turnType IS NOT NULL AND turnType != ''
            GROUP BY turnType
            ORDER BY jam_count DESC
        ";
        
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Método adicional: Obtém coordenadas geográficas dos congestionamentos
     * para criar um mapa de calor
     */
    public function getHeatmapData($limit = 1000) {
        $query = "
            SELECT 
                j.uuid, j.delay, j.level,
                jl.x, jl.y
            FROM jams j
            JOIN jam_lines jl ON j.uuid = jl.jam_uuid
            ORDER BY j.date_received DESC
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Exemplo de uso:
try {
    // Assumindo que $pdo já está disponível
    $analyzer = new TrafficJamAnalysis($pdo);
    $data = $analyzer->getAllAnalytics();
    
    // Você pode acessar dados específicos assim:
    // $topStreets = $data['top_streets'];
    // $hourlyData = $data['hourly_distribution'];
    
    // Opcional: Incluir análises adicionais
    $data['turn_type_analysis'] = $analyzer->getTurnTypeAnalysis();
    $data['heatmap_data'] = $analyzer->getHeatmapData(500); // Limita a 500 pontos
    
    // O array $data agora contém todas as análises
    
    // Opcional: retornar como JSON (descomentar se necessário)
    // header('Content-Type: application/json');
    // echo json_encode($data);
    
} catch (PDOException $e) {
    // Tratamento de erro
    // error_log("Erro na análise de congestionamentos: " . $e->getMessage());
    // echo "Ocorreu um erro ao analisar os dados.";
}
?>