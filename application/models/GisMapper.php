<?php

/* Copyright 2016 by Alexander Watzinger and others. Please see the file README.md for licensing information */

class Model_GisMapper extends Model_AbstractMapper {

    public static function getAll($objectIdsParam = []) {
        $objectIds = (is_array($objectIdsParam)) ? $objectIdsParam : [$objectIdsParam];
        foreach (['point', 'polygon'] as $shape) {
            $sql = "
                SELECT
                    object.id AS object_id,
                    " . $shape . ".id,
                    " . $shape . ".name,
                    " . $shape . ".description,
                    " . $shape . ".type,
                    ST_AsGeoJSON(" . $shape . ".geom) AS geojson,
                    object.name AS object_name,
                    object.description AS object_description,
                    (SELECT COUNT(*) FROM gis.point point2 WHERE " . $shape . ".entity_id = point2.entity_id) AS point_count,
                    (SELECT COUNT(*) FROM gis.polygon polygon2 WHERE " . $shape . ".entity_id = polygon2.entity_id) AS polygon_count,
                    array_to_json(ARRAY(
                        SELECT l.range_id
                        FROM model.link l
                        JOIN model.property p ON l.property_id = p.id
                        WHERE l.domain_id = object.id AND p.code = 'P2'
                    )) AS node_ids
                FROM model.entity place
                JOIN model.link l ON place.id = l.range_id
                JOIN model.entity object ON l.domain_id = object.id
                JOIN gis." . $shape . " " . $shape . " ON place.id = " . $shape . ".entity_id
                WHERE
                    place.class_id = (SELECT id FROM model.class WHERE code LIKE 'E53') AND
                    l.property_id = (SELECT id FROM model.property WHERE code LIKE 'P53');
                ";
            $statement = Zend_Db_Table::getDefaultAdapter()->prepare($sql);
            $statement->execute();
            $all = [];
            $selected = [];
            foreach ($statement->fetchAll() as $row) {
                $type = '';
                foreach (json_decode($row['node_ids']) as $nodeId) {
                    $node = Model_NodeMapper::getById($nodeId);
                    if ($node->rootId && Model_NodeMapper::getById($node->rootId)->name == 'Site') {
                        $type = $node->name;
                    }
                }
                $item = [
                    'type' => 'Feature',
                    'geometry' => json_decode($row['geojson']),
                    'properties' => [
                        'title' => str_replace('"', '\"', $row['object_name']),
                        'objectId' => (int) $row['object_id'],
                        'objectDescription' => str_replace('"', '\"', $row['object_description']),
                        'id' => (int) $row['id'],
                        'name' => str_replace('"', '\"', $row['name']),
                        'description' => str_replace('"', '\"', $row['description']),
                        'siteType' => $type,
                        'shapeType' => $row['type'],
                        'count' => $row['point_count'] + $row['polygon_count']
                    ]
                ];
                if (in_array($row['object_id'], $objectIds)) {
                    $selected[] = $item;
                } else {
                    $all[] = $item;
                }
            }
            $gis['gis' . ucfirst($shape) . 'All'] = json_encode($all);
            $gis['gis' . ucfirst($shape) . 'Selected'] = json_encode($selected);
        }
        return $gis;
    }

    public static function insertPolygons(Model_Entity $place, $polygons) {
        foreach ($polygons as $polygon) {
            $sql = "INSERT INTO gis.polygon (entity_id, name, description, type, geom)
                VALUES (
                    :entity_id,
                    :name,
                    :description,
                    :type,
                    ST_SetSRID(ST_GeomFromGeoJSON(:geojson),4326)
                );";
            $statement = Zend_Db_Table::getDefaultAdapter()->prepare($sql);
            $statement->bindValue(':entity_id', $place->id);
            $statement->bindValue(':name', $polygon->properties->name);
            $statement->bindValue(':description', $polygon->properties->description);
            $statement->bindValue(':type', $polygon->properties->shapeType);
            $statement->bindValue(':geojson', json_encode($polygon->geometry));
            $statement->execute();
        }
    }

    public static function insertPoints(Model_Entity $place, $points) {
        foreach ($points as $point) {
            $sql = "INSERT INTO gis.point (entity_id, name, description, type, geom)
                VALUES (
                    :entity_id,
                    :name,
                    :description,
                    :type,
                    ST_SetSRID(ST_GeomFromGeoJSON(:geojson),4326)
                );";
            $statement = Zend_Db_Table::getDefaultAdapter()->prepare($sql);
            $statement->bindValue(':entity_id', $place->id);
            $statement->bindValue(':name', $point->properties->name);
            $statement->bindValue(':description', $point->properties->description);
            $statement->bindValue(':type', $point->properties->shapeType);
            $statement->bindValue(':geojson', json_encode($point->geometry));
            $statement->execute();
        }
    }

    public static function getPolygons(Model_Entity $object) {
        $sql = 'SELECT id, name, description, type, geom FROM gis.polygon WHERE entity_id = :entity_id;';
        $statement = Zend_Db_Table::getDefaultAdapter()->prepare($sql);
        $statement->bindValue(':entity_id', $object->id);
        $statement->execute();
        $result = $statement->fetch();
        if ($result) {
            $polygon['id'] = $result['id'];
            $polygon['name'] = $result['name'];
            $polygon['description'] = $result['description'];
            $polygon['type'] = $result['type'];
            $polygon['geom'] = $result['geom'];
            return $polygon;
        }
        return false;
    }

    public static function getByEntity(Model_Entity $entity) {
        $sql = 'SELECT st_x(st_transform(geom,4326)) as easting, st_y(st_transform(geom,4326)) as northing
            FROM gis.point WHERE entity_id = :entity_id;';
        $statement = Zend_Db_Table::getDefaultAdapter()->prepare($sql);
        $statement->bindValue(':entity_id', $entity->id);
        $statement->execute();
        $result = $statement->fetch();
        if ($result) {
            $gis = new Model_Gis();
            $gis->easting = $result['easting'];
            $gis->northing = $result['northing'];
            $gis->setEntity($entity);
            return $gis;
        }
        return false;
    }

    public static function deleteByEntity($entity) {
        $sql = 'DELETE FROM gis.point WHERE entity_id = :entity_id;';
        $statement = Zend_Db_Table::getDefaultAdapter()->prepare($sql);
        $statement->bindValue('entity_id', $entity->id);
        $statement->execute();
        $sqlPolygon = 'DELETE FROM gis.polygon WHERE entity_id = :entity_id;';
        $statementPolygon = Zend_Db_Table::getDefaultAdapter()->prepare($sqlPolygon);
        $statementPolygon->bindValue('entity_id', $entity->id);
        $statementPolygon->execute();
    }

}
