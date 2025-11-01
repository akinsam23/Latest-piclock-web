<?php

class LocationManager {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }

    // Country methods
    public function getAllCountries() {
        $stmt = $this->db->query("SELECT * FROM countries ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCountryById($id) {
        $stmt = $this->db->prepare("SELECT * FROM countries WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getCountryByCode($code, $type = 'iso2') {
        $validTypes = ['iso2', 'iso3'];
        if (!in_array($type, $validTypes)) {
            throw new InvalidArgumentException("Invalid country code type. Must be 'iso2' or 'iso3'.");
        }
        
        $column = $type === 'iso2' ? 'iso2' : 'iso3';
        $stmt = $this->db->prepare("SELECT * FROM countries WHERE $column = ?");
        $stmt->execute([strtoupper($code)]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // State methods
    public function getStatesByCountry($countryId) {
        $stmt = $this->db->prepare("SELECT * FROM states WHERE country_id = ? ORDER BY name ASC");
        $stmt->execute([$countryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStateById($id) {
        $stmt = $this->db->prepare("SELECT s.*, c.iso2 as country_code, c.name as country_name 
                                  FROM states s 
                                  JOIN countries c ON s.country_id = c.id 
                                  WHERE s.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // City methods
    public function getCitiesByState($stateId) {
        $stmt = $this->db->prepare("SELECT * FROM cities WHERE state_id = ? ORDER BY name ASC");
        $stmt->execute([$stateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCityById($id) {
        $stmt = $this->db->prepare("SELECT c.*, s.name as state_name, s.state_code, 
                                  co.iso2 as country_code, co.name as country_name 
                                  FROM cities c 
                                  JOIN states s ON c.state_id = s.id 
                                  JOIN countries co ON c.country_id = co.id 
                                  WHERE c.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Location methods
    public function createLocation($data) {
        $required = ['country_id', 'name'];
        $this->validateRequiredFields($data, $required);

        $sql = "INSERT INTO locations (country_id, state_id, city_id, name, address, latitude, longitude) 
                VALUES (:country_id, :state_id, :city_id, :name, :address, :latitude, :longitude)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':country_id' => $data['country_id'],
            ':state_id' => $data['state_id'] ?? null,
            ':city_id' => $data['city_id'] ?? null,
            ':name' => $data['name'],
            ':address' => $data['address'] ?? null,
            ':latitude' => $data['latitude'] ?? null,
            ':longitude' => $data['longitude'] ?? null
        ]);

        return $this->db->lastInsertId();
    }

    public function getLocation($id) {
        $sql = "SELECT l.*, c.name as city_name, s.name as state_name, co.name as country_name,
                       co.iso2 as country_code, s.state_code
                FROM locations l
                LEFT JOIN cities c ON l.city_id = c.id
                LEFT JOIN states s ON l.state_id = s.id OR (l.state_id IS NULL AND c.state_id = s.id)
                LEFT JOIN countries co ON l.country_id = co.id
                WHERE l.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function searchLocations($query, $countryId = null, $limit = 10) {
        $sql = "SELECT l.*, 
                       COALESCE(ci.name, s.name, co.name) as display_name,
                       CASE 
                           WHEN ci.name IS NOT NULL THEN CONCAT(ci.name, ', ', s.name, ', ', co.name)
                           WHEN s.name IS NOT NULL THEN CONCAT(s.name, ', ', co.name)
                           ELSE co.name
                       END as full_address
                FROM locations l
                LEFT JOIN countries co ON l.country_id = co.id
                LEFT JOIN states s ON l.state_id = s.id
                LEFT JOIN cities ci ON l.city_id = ci.id
                WHERE l.name LIKE :query";
        
        $params = [':query' => "%$query%"];
        
        if ($countryId) {
            $sql .= " AND l.country_id = :country_id";
            $params[':country_id'] = $countryId;
        }
        
        $sql .= " ORDER BY 
                    CASE 
                        WHEN ci.name IS NOT NULL THEN 1
                        WHEN s.name IS NOT NULL THEN 2
                        ELSE 3
                    END, l.name
                LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':query', "%$query%", PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        if ($countryId) {
            $stmt->bindValue(':country_id', $countryId, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLocationHierarchy($locationId) {
        $sql = "SELECT 
                    l.id as location_id, l.name as location_name, l.address,
                    c.id as city_id, c.name as city_name,
                    s.id as state_id, s.name as state_name, s.state_code,
                    co.id as country_id, co.name as country_name, co.iso2 as country_code
                FROM locations l
                LEFT JOIN cities c ON l.city_id = c.id
                LEFT JOIN states s ON l.state_id = s.id OR (l.state_id IS NULL AND c.state_id = s.id)
                LEFT JOIN countries co ON l.country_id = co.id
                WHERE l.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$locationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Helper method to validate required fields
    private function validateRequiredFields($data, $required) {
        $missing = [];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new InvalidArgumentException("Missing required fields: " . implode(', ', $missing));
        }
    }

    // Import countries from a JSON file
    public function importCountriesFromJson($jsonFile) {
        if (!file_exists($jsonFile)) {
            throw new RuntimeException("JSON file not found: $jsonFile");
        }
        
        $json = file_get_contents($jsonFile);
        $countries = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON: " . json_last_error_msg());
        }
        
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO countries (name, iso2, iso3, phone_code, capital, currency, native_name, region, subregion, emoji, emojiU)
                VALUES (:name, :iso2, :iso3, :phone_code, :capital, :currency, :native_name, :region, :subregion, :emoji, :emojiU)
                ON DUPLICATE KEY UPDATE 
                    name = VALUES(name),
                    phone_code = VALUES(phone_code),
                    capital = VALUES(capital),
                    currency = VALUES(currency),
                    native_name = VALUES(native_name),
                    region = VALUES(region),
                    subregion = VALUES(subregion),
                    emoji = VALUES(emoji),
                    emojiU = VALUES(emojiU)
            ");
            
            foreach ($countries as $country) {
                $stmt->execute([
                    ':name' => $country['name'],
                    ':iso2' => $country['iso2'],
                    ':iso3' => $country['iso3'],
                    ':phone_code' => $country['phone_code'] ?? null,
                    ':capital' => $country['capital'] ?? null,
                    ':currency' => $country['currency'] ?? null,
                    ':native_name' => $country['native'] ?? null,
                    ':region' => $country['region'] ?? null,
                    ':subregion' => $country['subregion'] ?? null,
                    ':emoji' => $country['emoji'] ?? null,
                    ':emojiU' => $country['emojiU'] ?? null
                ]);
            }
            
            $this->db->commit();
            return ['success' => true, 'message' => 'Countries imported successfully'];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to import countries: ' . $e->getMessage()];
        }
    }
}
