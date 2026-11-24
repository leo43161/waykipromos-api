-- =====================================================================================================
-- Propuesta Contrato API v1 · Wayki Promo (Ente Autárquico Tucumán Turismo)
-- Generado conforme al Contrato de API v1 (19 de noviembre de 2026)
-- Esquema objetivo: huakipromo
-- =====================================================================================================

DELIMITER $$

-- -----------------------------------------------------------------------------------------------------
-- 1. Tablas complementarias y nuevas columnas
-- -----------------------------------------------------------------------------------------------------

-- Intentos de login para rate limiting (5 por usuario, 20 por IP en 15 min)
CREATE TABLE IF NOT EXISTS login_intentos (
  intento_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombreu VARCHAR(100) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  exito TINYINT(1) NOT NULL DEFAULT 0,
  ocurrido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_intentos_nombreu (nombreu, exito, ocurrido_en),
  INDEX idx_login_intentos_ip (ip, exito, ocurrido_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$$

-- Registro de actividad y auditoría
CREATE TABLE IF NOT EXISTS actividad (
  actividad_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  accion VARCHAR(40) NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  usuario_id BIGINT UNSIGNED NULL,
  turistas_id INT NULL,
  comercio_id BIGINT UNSIGNED NULL,
  entidad VARCHAR(30) NOT NULL,
  entidad_id BIGINT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  ocurrido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_actividad_comercio (comercio_id, ocurrido_en),
  INDEX idx_actividad_usuario (usuario_id, ocurrido_en),
  INDEX idx_actividad_accion (accion, ocurrido_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$$

-- -----------------------------------------------------------------------------------------------------
-- 2. Vista de promociones públicas y de gestión
-- -----------------------------------------------------------------------------------------------------

CREATE OR REPLACE VIEW vw_promociones AS
SELECT p.promocion_id, p.titulo, p.descripcion, p.condiciones, p.imagen_url,
       p.fecha_inicio, p.fecha_fin, p.dias_validos, p.hora_inicio, p.hora_fin,
       p.stock_total, p.stock_disponible, p.estado, p.orden, p.creado_por, p.creado_en, p.actualizado_en,
       p.modalidad_id, m.nombre AS modalidad_nombre, m.tipo AS modalidad_tipo, m.valor AS modalidad_valor,
       p.modalidad_personalizada,
       CASE WHEN m.tipo = 'PERSONALIZADO' AND COALESCE(p.modalidad_personalizada, '') <> ''
            THEN p.modalidad_personalizada ELSE REPLACE(m.nombre, 'x', '×') END AS descuento_visual,
       p.categoria_id, cat.nombre AS categoria_nombre,
       c.comercio_id, c.nombre AS comercio_nombre, c.direccion AS comercio_direccion,
       c.telefono_contacto AS comercio_telefono, c.logo_url AS comercio_logo_url, c.estado_autorizacion AS comercio_estado,
       l.localidad_id, l.nombre AS localidad_nombre,
       ct.circuitosturisticos_id AS circuito_id, ct.nombre AS circuito_nombre,
       (p.estado = 'PUBLICADA' AND p.activo = 1 AND c.estado_autorizacion = 'AUTORIZADO' AND c.activo = 1
        AND c.borrado = 0 AND CURDATE() BETWEEN p.fecha_inicio AND p.fecha_fin) AS publica
FROM promociones p
JOIN comercios c             ON c.comercio_id = p.comercio_id
JOIN localidades l           ON l.localidad_id = c.localidad_id
JOIN categorias_comercio cat ON cat.categoria_id = p.categoria_id
JOIN modalidades_descuento m ON m.modalidad_id = p.modalidad_id
LEFT JOIN circuitosturisticos ct ON ct.circuitosturisticos_id = COALESCE(p.circuitosturisticos_id, l.circuitosturisticos_id)
WHERE p.borrado = 0$$

-- -----------------------------------------------------------------------------------------------------
-- 3. Reglas y validación de canje (Función atómica)
-- -----------------------------------------------------------------------------------------------------

DROP FUNCTION IF EXISTS fn_cupon_problema$$
CREATE FUNCTION fn_cupon_problema(p_cupon_id BIGINT UNSIGNED, p_comercio_id BIGINT UNSIGNED)
RETURNS VARCHAR(30) NOT DETERMINISTIC READS SQL DATA
BEGIN
  DECLARE v_estado VARCHAR(20) DEFAULT NULL;
  DECLARE v_vence DATETIME;
  DECLARE v_comercio BIGINT UNSIGNED;
  DECLARE v_comercio_ok INT;
  DECLARE v_dias VARCHAR(40);
  DECLARE v_hi TIME;
  DECLARE v_hf TIME;
  DECLARE v_hoy VARCHAR(3) DEFAULT ELT(WEEKDAY(NOW()) + 1, 'LUN', 'MAR', 'MIE', 'JUE', 'VIE', 'SAB', 'DOM');

  SELECT cu.estado, cu.vence_en, p.comercio_id,
         (c.estado_autorizacion = 'AUTORIZADO' AND c.activo = 1 AND c.borrado = 0),
         p.dias_validos, p.hora_inicio, p.hora_fin
    INTO v_estado, v_vence, v_comercio, v_comercio_ok, v_dias, v_hi, v_hf
    FROM cupones cu
    JOIN promociones p ON p.promocion_id = cu.promocion_id
    JOIN comercios c   ON c.comercio_id = p.comercio_id
   WHERE cu.cupon_id = p_cupon_id AND cu.borrado = 0;

  IF v_estado IS NULL THEN RETURN 'CUPON_INEXISTENTE'; END IF;
  IF p_comercio_id IS NOT NULL AND v_comercio <> p_comercio_id THEN RETURN 'CUPON_OTRO_COMERCIO'; END IF;
  IF v_estado = 'CANJEADO' THEN RETURN 'CUPON_YA_CANJEADO'; END IF;
  IF v_estado = 'ANULADO' THEN RETURN 'CUPON_ANULADO'; END IF;
  IF v_estado = 'VENCIDO' OR v_vence < NOW() THEN RETURN 'CUPON_VENCIDO'; END IF;
  IF v_comercio_ok <> 1 THEN RETURN 'COMERCIO_SUSPENDIDO'; END IF;
  IF IFNULL(v_dias, '') <> '' AND FIND_IN_SET(v_hoy, v_dias) = 0 THEN RETURN 'FUERA_DE_DIA'; END IF;
  IF v_hi IS NOT NULL AND v_hf IS NOT NULL AND NOT (
       (v_hi <= v_hf AND CURTIME() BETWEEN v_hi AND v_hf)
    OR (v_hi > v_hf AND (CURTIME() >= v_hi OR CURTIME() <= v_hf))
  ) THEN RETURN 'FUERA_DE_HORARIO'; END IF;
  RETURN NULL;
END$$

-- -----------------------------------------------------------------------------------------------------
-- 4. Procedimientos Almacenados: Catálogo y Turista
-- -----------------------------------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS sp_promociones_catalogo$$
CREATE PROCEDURE sp_promociones_catalogo(
  IN p_promocion_id BIGINT UNSIGNED, IN p_categoria_id SMALLINT UNSIGNED, IN p_localidad_id BIGINT UNSIGNED,
  IN p_circuito_id INT, IN p_texto VARCHAR(100), IN p_limite INT, IN p_offset INT)
BEGIN
  SELECT v.*, (v.orden = 0) AS destacada, COUNT(*) OVER () AS total
  FROM vw_promociones v
  WHERE v.publica = 1
    AND (IFNULL(p_promocion_id, 0) = 0 AND v.stock_disponible > 0 OR v.promocion_id = p_promocion_id)
    AND (IFNULL(p_categoria_id, 0) = 0 OR v.categoria_id = p_categoria_id)
    AND (IFNULL(p_localidad_id, 0) = 0 OR v.localidad_id = p_localidad_id)
    AND (IFNULL(p_circuito_id, 0) = 0 OR v.circuito_id = p_circuito_id)
    AND (IFNULL(p_texto, '') = '' OR CONCAT_WS(' ', v.titulo, v.descripcion, v.comercio_nombre, v.localidad_nombre)
                                      LIKE CONCAT('%', p_texto, '%'))
  ORDER BY v.orden, v.fecha_fin, v.promocion_id
  LIMIT p_limite OFFSET p_offset;
END$$

DROP PROCEDURE IF EXISTS sp_turistas_perfil$$
CREATE PROCEDURE sp_turistas_perfil(IN p_turistas_id INT)
BEGIN
  SELECT t.turistas_id, t.nombre, t.apellido, t.nombre_completo, t.email, t.foto_url, t.creado_en, t.activo,
         EXISTS (SELECT 1 FROM encuestas_turisticas e WHERE e.turistas_id = t.turistas_id AND e.borrado = 0) AS encuesta_respondida,
         (SELECT COUNT(*) FROM cupones cu WHERE cu.turistas_id = t.turistas_id AND cu.borrado = 0
            AND cu.estado = 'EMITIDO' AND cu.vence_en >= NOW()) AS cupones_para_usar,
         (SELECT COUNT(*) FROM cupones cu WHERE cu.turistas_id = t.turistas_id AND cu.borrado = 0
            AND cu.estado = 'CANJEADO') AS cupones_usados,
         (SELECT COUNT(*) FROM cupones cu WHERE cu.turistas_id = t.turistas_id AND cu.borrado = 0
            AND (cu.estado = 'VENCIDO' OR (cu.estado = 'EMITIDO' AND cu.vence_en < NOW()))) AS cupones_vencidos
  FROM turistas t
  WHERE t.turistas_id = p_turistas_id AND t.borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_turistas_anonimizar$$
CREATE PROCEDURE sp_turistas_anonimizar(IN p_turistas_id INT)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  START TRANSACTION;
  UPDATE promociones p
  JOIN (SELECT promocion_id, COUNT(*) AS n FROM cupones
         WHERE turistas_id = p_turistas_id AND estado = 'EMITIDO' AND borrado = 0 GROUP BY promocion_id) x
    ON x.promocion_id = p.promocion_id
  SET p.stock_disponible = LEAST(p.stock_total, p.stock_disponible + x.n);
  UPDATE cupones SET estado = 'ANULADO' WHERE turistas_id = p_turistas_id AND estado = 'EMITIDO';
  UPDATE turistas
     SET google_sub = CONCAT('borrado-', turistas_id), email = CONCAT('borrado-', turistas_id, '@invalid'),
         email_verified = NULL, nombre_completo = NULL, foto_url = NULL, nombre = NULL, apellido = NULL,
         activo = 0, borrado = 1
   WHERE turistas_id = p_turistas_id;
  COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_encuesta_opciones$$
CREATE PROCEDURE sp_encuesta_opciones()
BEGIN
  SELECT 'rangos_edad' AS grupo, rango_edad_id AS id, etiqueta AS nombre, orden FROM rangos_edad WHERE activo = 1 AND borrado = 0
  UNION ALL SELECT 'duraciones', duracion_id, etiqueta, orden FROM duraciones_estadia WHERE activo = 1 AND borrado = 0
  UNION ALL SELECT 'motivos', motivo_id, nombre, motivo_id FROM motivos_visita WHERE activo = 1 AND borrado = 0
  UNION ALL SELECT 'companias', companias_id, nombre, companias_id FROM companias WHERE activo = 1 AND borrado = 0
  UNION ALL SELECT 'medios', rrss_id, nombre, rrss_id FROM rrss WHERE activo = 1 AND borrado = 0
  UNION ALL SELECT 'provincias', provincia_id, nombre, 0 FROM provincias WHERE pais_id = 1 AND activo = 1 AND borrado = 0
  UNION ALL SELECT 'paises', pais_id, nombre, 0 FROM paises WHERE pais_id <> 1 AND activo = 1 AND borrado = 0
  ORDER BY grupo, orden, nombre;
END$$

DROP PROCEDURE IF EXISTS sp_encuestas_turisticas_del_turista$$
CREATE PROCEDURE sp_encuestas_turisticas_del_turista(IN p_turistas_id INT)
BEGIN
  SELECT e.respondida_en,
         re.rango_edad_id, re.etiqueta AS rango_edad, d.duracion_id, d.etiqueta AS duracion,
         m.motivo_id, m.nombre AS motivo, co.companias_id AS compania_id, co.nombre AS compania,
         r.rrss_id AS medio_id, r.nombre AS medio,
         pr.provincia_id, pr.nombre AS provincia, pa.pais_id, pa.nombre AS pais
  FROM encuestas_turisticas e
  JOIN rangos_edad re        ON re.rango_edad_id = e.rango_edad_id
  JOIN duraciones_estadia d  ON d.duracion_id = e.duracion_id
  JOIN motivos_visita m      ON m.motivo_id = e.motivos_visita_motivo_id
  JOIN companias co          ON co.companias_id = e.companias_companias_id
  JOIN rrss r                ON r.rrss_id = e.rrss_rrss_id
  LEFT JOIN provincias pr    ON pr.provincia_id = e.provincias_provincia_id
  LEFT JOIN paises pa        ON pa.pais_id = e.pais_procedencia_id
  WHERE e.turistas_id = p_turistas_id AND e.borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_encuestas_turisticas_guardar$$
CREATE PROCEDURE sp_encuestas_turisticas_guardar(
  IN p_turistas_id INT, IN p_rango_edad_id SMALLINT UNSIGNED, IN p_duracion_id SMALLINT UNSIGNED,
  IN p_motivo_id SMALLINT UNSIGNED, IN p_compania_id INT, IN p_medio_id INT,
  IN p_provincia_id BIGINT UNSIGNED, IN p_pais_id BIGINT UNSIGNED)
BEGIN
  INSERT INTO encuestas_turisticas (turistas_id, rango_edad_id, duracion_id, motivos_visita_motivo_id, companias_companias_id,
                                    rrss_rrss_id, provincias_provincia_id, pais_procedencia_id)
  VALUES (p_turistas_id, p_rango_edad_id, p_duracion_id, p_motivo_id, p_compania_id, p_medio_id,
          NULLIF(p_provincia_id, 0), NULLIF(p_pais_id, 0))
  ON DUPLICATE KEY UPDATE rango_edad_id = VALUES(rango_edad_id), duracion_id = VALUES(duracion_id),
    motivos_visita_motivo_id = VALUES(motivos_visita_motivo_id), companias_companias_id = VALUES(companias_companias_id),
    rrss_rrss_id = VALUES(rrss_rrss_id), provincias_provincia_id = VALUES(provincias_provincia_id),
    pais_procedencia_id = VALUES(pais_procedencia_id), respondida_en = CURRENT_TIMESTAMP, activo = 1, borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_cupones_emitir$$
CREATE PROCEDURE sp_cupones_emitir(
  IN p_turistas_id INT, IN p_promocion_id BIGINT UNSIGNED, IN p_codigo VARCHAR(32), IN p_qr_hash CHAR(64))
proc: BEGIN
  DECLARE v_estado VARCHAR(20) DEFAULT NULL;
  DECLARE v_stock INT DEFAULT 0;
  DECLARE v_fin DATE;
  DECLARE v_publica INT DEFAULT 0;
  DECLARE v_existente VARCHAR(32) DEFAULT NULL;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  IF NOT EXISTS (SELECT 1 FROM encuestas_turisticas WHERE turistas_id = p_turistas_id AND borrado = 0) THEN
    SELECT 'ENCUESTA_PENDIENTE' AS resultado, NULL AS codigo;
    LEAVE proc;
  END IF;

  START TRANSACTION;
  SELECT p.estado, p.stock_disponible, p.fecha_fin INTO v_estado, v_stock, v_fin
    FROM promociones p WHERE p.promocion_id = p_promocion_id AND p.borrado = 0 FOR UPDATE;
  IF v_estado IS NULL THEN
    ROLLBACK; SELECT 'PROMOCION_INEXISTENTE' AS resultado, NULL AS codigo; LEAVE proc;
  END IF;

  SELECT codigo INTO v_existente FROM cupones
   WHERE turistas_id = p_turistas_id AND promocion_id = p_promocion_id AND borrado = 0 LIMIT 1;
  IF v_existente IS NOT NULL THEN
    COMMIT; SELECT 'YA_LO_TENIA' AS resultado, v_existente AS codigo; LEAVE proc;
  END IF;

  SELECT publica INTO v_publica FROM vw_promociones WHERE promocion_id = p_promocion_id;
  IF v_publica <> 1 THEN
    ROLLBACK; SELECT 'PROMOCION_NO_DISPONIBLE' AS resultado, NULL AS codigo; LEAVE proc;
  END IF;
  IF v_stock <= 0 THEN
    ROLLBACK; SELECT 'STOCK_AGOTADO' AS resultado, NULL AS codigo; LEAVE proc;
  END IF;

  INSERT INTO cupones (codigo, qr_hash, promocion_id, vence_en, turistas_id)
  VALUES (p_codigo, p_qr_hash, p_promocion_id, TIMESTAMP(v_fin, '23:59:59'), p_turistas_id);
  UPDATE promociones SET stock_disponible = stock_disponible - 1 WHERE promocion_id = p_promocion_id;
  COMMIT;
  SELECT 'EMITIDO' AS resultado, p_codigo AS codigo;
END$$

DROP PROCEDURE IF EXISTS sp_cupones_del_turista$$
CREATE PROCEDURE sp_cupones_del_turista(IN p_turistas_id INT, IN p_codigo VARCHAR(32))
BEGIN
  SELECT cu.cupon_id, cu.codigo, cu.emitido_en, cu.vence_en, ca.canjeado_en,
         CASE WHEN cu.estado = 'EMITIDO' AND cu.vence_en < NOW() THEN 'VENCIDO' ELSE cu.estado END AS estado_cupon,
         v.*, (v.orden = 0) AS destacada
  FROM cupones cu
  JOIN vw_promociones v ON v.promocion_id = cu.promocion_id
  LEFT JOIN canjes ca   ON ca.cupon_id = cu.cupon_id AND ca.borrado = 0
  WHERE cu.turistas_id = p_turistas_id AND cu.borrado = 0
    AND (IFNULL(p_codigo, '') = '' OR cu.codigo = p_codigo)
  ORDER BY (cu.estado = 'EMITIDO' AND cu.vence_en >= NOW()) DESC, cu.vence_en, cu.emitido_en DESC;
END$$

-- -----------------------------------------------------------------------------------------------------
-- 5. Procedimientos Almacenados: Sesión, Personal y Mostrador
-- -----------------------------------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS sp_login_intentos_fallidos$$
CREATE PROCEDURE sp_login_intentos_fallidos(IN p_nombreu VARCHAR(100), IN p_ip VARCHAR(45), IN p_minutos INT)
BEGIN
  SELECT
    (SELECT COUNT(*) FROM login_intentos
      WHERE nombreu = p_nombreu AND exito = 0 AND ocurrido_en > NOW() - INTERVAL p_minutos MINUTE
        AND ocurrido_en > IFNULL((SELECT MAX(ocurrido_en) FROM login_intentos WHERE nombreu = p_nombreu AND exito = 1), '1000-01-01')
    ) AS fallidos_usuario,
    (SELECT COUNT(*) FROM login_intentos
      WHERE ip = p_ip AND exito = 0 AND ocurrido_en > NOW() - INTERVAL p_minutos MINUTE) AS fallidos_ip;
END$$

DROP PROCEDURE IF EXISTS sp_login_intento_registrar$$
CREATE PROCEDURE sp_login_intento_registrar(
  IN p_nombreu VARCHAR(100), IN p_ip VARCHAR(45), IN p_exito TINYINT, IN p_usuario_id BIGINT UNSIGNED)
BEGIN
  INSERT INTO login_intentos (nombreu, ip, exito) VALUES (p_nombreu, p_ip, p_exito);
  IF p_exito = 1 AND IFNULL(p_usuario_id, 0) > 0 THEN
    UPDATE usuarios SET ultimo_acceso = NOW() WHERE usuario_id = p_usuario_id;
  END IF;
END$$

DROP PROCEDURE IF EXISTS sp_usuarios_sesion$$
CREATE PROCEDURE sp_usuarios_sesion(IN p_usuario_id BIGINT UNSIGNED)
BEGIN
  SELECT u.usuario_id, u.nombreu, u.nombre, u.email, u.telefono, u.estado, u.ultimo_acceso,
         (SELECT r.nombre FROM usuario_roles ur JOIN roles r ON r.rol_id = ur.rol_id
           WHERE ur.usuario_id = u.usuario_id AND ur.borrado = 0 AND r.activo = 1 AND r.borrado = 0
           ORDER BY r.rol_id LIMIT 1) AS rol,
         c.comercio_id, c.nombre AS comercio_nombre, c.estado_autorizacion AS comercio_estado
  FROM usuarios u
  LEFT JOIN comercios c ON c.comercio_id = u.comercio_id AND c.borrado = 0
  WHERE u.usuario_id = p_usuario_id AND u.borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_usuarios_cambiar_password$$
CREATE PROCEDURE sp_usuarios_cambiar_password(IN p_usuario_id BIGINT UNSIGNED, IN p_password_hash VARCHAR(255))
BEGIN
  UPDATE usuarios SET password_hash = p_password_hash WHERE usuario_id = p_usuario_id AND borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_canjes_validar$$
CREATE PROCEDURE sp_canjes_validar(IN p_codigo VARCHAR(32), IN p_comercio_id BIGINT UNSIGNED)
BEGIN
  SELECT fn_cupon_problema(cu.cupon_id, p_comercio_id) AS problema,
         cu.cupon_id, cu.codigo, cu.emitido_en, cu.vence_en,
         CASE WHEN cu.estado = 'EMITIDO' AND cu.vence_en < NOW() THEN 'VENCIDO' ELSE cu.estado END AS estado,
         v.promocion_id, v.titulo, v.descuento_visual, v.condiciones, v.dias_validos, v.hora_inicio, v.hora_fin,
         v.comercio_id, v.comercio_nombre,
         CONCAT_WS(' ', t.nombre, CONCAT(LEFT(t.apellido, 1), '.')) AS turista_nombre,
         ca.canjeado_en, uc.usuario_id AS canjeado_por_id, COALESCE(uc.nombre, uc.nombreu) AS canjeado_por_nombre
  FROM cupones cu
  JOIN vw_promociones v ON v.promocion_id = cu.promocion_id
  JOIN turistas t       ON t.turistas_id = cu.turistas_id
  LEFT JOIN canjes ca   ON ca.cupon_id = cu.cupon_id AND ca.borrado = 0
  LEFT JOIN usuarios uc ON uc.usuario_id = ca.usuario_id
  WHERE cu.codigo = p_codigo AND cu.borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_canjes_confirmar$$
CREATE PROCEDURE sp_canjes_confirmar(
  IN p_codigo VARCHAR(32), IN p_usuario_id BIGINT UNSIGNED, IN p_comercio_id BIGINT UNSIGNED, IN p_observaciones VARCHAR(500))
proc: BEGIN
  DECLARE v_cupon BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_problema VARCHAR(30);
  DECLARE v_canje BIGINT UNSIGNED;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  START TRANSACTION;
  SELECT cupon_id INTO v_cupon FROM cupones WHERE codigo = p_codigo AND borrado = 0 FOR UPDATE;
  IF v_cupon IS NULL THEN
    ROLLBACK; SELECT 'CUPON_INEXISTENTE' AS resultado, NULL AS canje_id, NULL AS canjeado_en; LEAVE proc;
  END IF;
  SET v_problema = fn_cupon_problema(v_cupon, p_comercio_id);
  IF v_problema IS NOT NULL THEN
    ROLLBACK; SELECT v_problema AS resultado, NULL AS canje_id, NULL AS canjeado_en; LEAVE proc;
  END IF;
  INSERT INTO canjes (cupon_id, usuario_id, observaciones, canjeado_en)
  VALUES (v_cupon, p_usuario_id, NULLIF(p_observaciones, ''), NOW());
  SET v_canje = LAST_INSERT_ID();
  UPDATE cupones SET estado = 'CANJEADO' WHERE cupon_id = v_cupon;
  COMMIT;
  SELECT 'CANJEADO' AS resultado, v_canje AS canje_id, NOW() AS canjeado_en;
END$$

DROP PROCEDURE IF EXISTS sp_canjes_listar$$
CREATE PROCEDURE sp_canjes_listar(
  IN p_comercio_id BIGINT UNSIGNED, IN p_usuario_id BIGINT UNSIGNED, IN p_promocion_id BIGINT UNSIGNED,
  IN p_desde DATE, IN p_hasta DATE, IN p_limite INT, IN p_offset INT)
BEGIN
  SELECT ca.canje_id, ca.canjeado_en, ca.observaciones, cu.codigo,
         v.promocion_id, v.titulo, v.descuento_visual, v.comercio_id, v.comercio_nombre,
         CONCAT_WS(' ', t.nombre, CONCAT(LEFT(t.apellido, 1), '.')) AS turista_nombre,
         u.usuario_id, COALESCE(u.nombre, u.nombreu) AS usuario_nombre,
         COUNT(*) OVER () AS total
  FROM canjes ca
  JOIN cupones cu       ON cu.cupon_id = ca.cupon_id
  JOIN vw_promociones v ON v.promocion_id = cu.promocion_id
  JOIN turistas t       ON t.turistas_id = cu.turistas_id
  JOIN usuarios u       ON u.usuario_id = ca.usuario_id
  WHERE ca.borrado = 0
    AND (IFNULL(p_comercio_id, 0) = 0 OR v.comercio_id = p_comercio_id)
    AND (IFNULL(p_usuario_id, 0) = 0 OR ca.usuario_id = p_usuario_id)
    AND (IFNULL(p_promocion_id, 0) = 0 OR v.promocion_id = p_promocion_id)
    AND (p_desde IS NULL OR ca.canjeado_en >= p_desde)
    AND (p_hasta IS NULL OR ca.canjeado_en < p_hasta + INTERVAL 1 DAY)
  ORDER BY ca.canjeado_en DESC
  LIMIT p_limite OFFSET p_offset;
END$$

-- -----------------------------------------------------------------------------------------------------
-- 6. Procedimientos Almacenados: Gestión, Reportes y Actividad
-- -----------------------------------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS sp_promociones_gestion$$
CREATE PROCEDURE sp_promociones_gestion(
  IN p_promocion_id BIGINT UNSIGNED, IN p_comercio_id BIGINT UNSIGNED, IN p_estado VARCHAR(20),
  IN p_categoria_id SMALLINT UNSIGNED, IN p_texto VARCHAR(100), IN p_limite INT, IN p_offset INT)
BEGIN
  SELECT v.*, (v.orden = 0) AS destacada, u.nombre AS creado_por_nombre, u.nombreu AS creado_por_usuario,
         (SELECT COUNT(*) FROM cupones cu WHERE cu.promocion_id = v.promocion_id AND cu.borrado = 0) AS emitidos,
         (SELECT COUNT(*) FROM cupones cu WHERE cu.promocion_id = v.promocion_id AND cu.borrado = 0 AND cu.estado = 'CANJEADO') AS canjeados,
         COUNT(*) OVER () AS total
  FROM vw_promociones v
  LEFT JOIN usuarios u ON u.usuario_id = v.creado_por
  WHERE (IFNULL(p_promocion_id, 0) = 0 OR v.promocion_id = p_promocion_id)
    AND (IFNULL(p_comercio_id, 0) = 0 OR v.comercio_id = p_comercio_id)
    AND (IFNULL(p_estado, '') = '' AND (v.estado <> 'BAJA' OR IFNULL(p_promocion_id, 0) > 0) OR v.estado = p_estado)
    AND (IFNULL(p_categoria_id, 0) = 0 OR v.categoria_id = p_categoria_id)
    AND (IFNULL(p_texto, '') = '' OR CONCAT_WS(' ', v.titulo, v.comercio_nombre, v.localidad_nombre) LIKE CONCAT('%', p_texto, '%'))
  ORDER BY FIELD(v.estado, 'PUBLICADA', 'BORRADOR', 'PAUSADA', 'FINALIZADA', 'BAJA'), v.orden, v.fecha_fin DESC
  LIMIT p_limite OFFSET p_offset;
END$$

DROP PROCEDURE IF EXISTS sp_promociones_cambiar_estado$$
CREATE PROCEDURE sp_promociones_cambiar_estado(IN p_promocion_id BIGINT UNSIGNED, IN p_estado VARCHAR(20))
BEGIN
  UPDATE promociones SET estado = p_estado WHERE promocion_id = p_promocion_id AND borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_promociones_imagen$$
CREATE PROCEDURE sp_promociones_imagen(IN p_promocion_id BIGINT UNSIGNED, IN p_url VARCHAR(255))
BEGIN
  UPDATE promociones SET imagen_url = p_url WHERE promocion_id = p_promocion_id AND borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_comercios_listar$$
CREATE PROCEDURE sp_comercios_listar(
  IN p_comercio_id BIGINT UNSIGNED, IN p_texto VARCHAR(100), IN p_estado VARCHAR(20), IN p_categoria_id SMALLINT UNSIGNED,
  IN p_localidad_id BIGINT UNSIGNED, IN p_limite INT, IN p_offset INT)
BEGIN
  SELECT c.comercio_id, c.nombre, c.direccion, c.email_contacto, c.telefono_contacto, c.logo_url,
         c.estado_autorizacion, c.observaciones_autorizacion, c.autorizado_en, c.creado_en,
         cat.categoria_id, cat.nombre AS categoria_nombre, l.localidad_id, l.nombre AS localidad_nombre,
         ct.circuitosturisticos_id AS circuito_id, ct.nombre AS circuito_nombre,
         (SELECT COUNT(*) FROM vw_promociones v WHERE v.comercio_id = c.comercio_id AND v.publica = 1) AS promociones_publicadas,
         (SELECT COUNT(*) FROM usuarios u WHERE u.comercio_id = c.comercio_id AND u.borrado = 0 AND u.estado = 'ACTIVO') AS usuarios_activos,
         (SELECT COUNT(*) FROM cupones cu JOIN promociones p ON p.promocion_id = cu.promocion_id
           WHERE p.comercio_id = c.comercio_id AND cu.borrado = 0) AS cupones_emitidos,
         (SELECT COUNT(*) FROM cupones cu JOIN promociones p ON p.promocion_id = cu.promocion_id
           WHERE p.comercio_id = c.comercio_id AND cu.borrado = 0 AND cu.estado = 'CANJEADO') AS cupones_canjeados,
         COUNT(*) OVER () AS total
  FROM comercios c
  JOIN categorias_comercio cat ON cat.categoria_id = c.categoria_id
  JOIN localidades l           ON l.localidad_id = c.localidad_id
  LEFT JOIN circuitosturisticos ct ON ct.circuitosturisticos_id = l.circuitosturisticos_id
  WHERE c.borrado = 0
    AND (IFNULL(p_comercio_id, 0) = 0 OR c.comercio_id = p_comercio_id)
    AND (IFNULL(p_estado, '') = '' OR c.estado_autorizacion = p_estado)
    AND (IFNULL(p_categoria_id, 0) = 0 OR c.categoria_id = p_categoria_id)
    AND (IFNULL(p_localidad_id, 0) = 0 OR c.localidad_id = p_localidad_id)
    AND (IFNULL(p_texto, '') = '' OR CONCAT_WS(' ', c.nombre, c.direccion, l.nombre, c.email_contacto) LIKE CONCAT('%', p_texto, '%'))
  ORDER BY c.nombre
  LIMIT p_limite OFFSET p_offset;
END$$

DROP PROCEDURE IF EXISTS sp_comercios_cambiar_estado$$
CREATE PROCEDURE sp_comercios_cambiar_estado(
  IN p_comercio_id BIGINT UNSIGNED, IN p_estado VARCHAR(20), IN p_observaciones VARCHAR(1000))
BEGIN
  UPDATE comercios
     SET estado_autorizacion = p_estado, observaciones_autorizacion = NULLIF(p_observaciones, ''),
         autorizado_en = IF(p_estado = 'AUTORIZADO', NOW(), autorizado_en)
   WHERE comercio_id = p_comercio_id AND borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_comercios_logo$$
CREATE PROCEDURE sp_comercios_logo(IN p_comercio_id BIGINT UNSIGNED, IN p_url VARCHAR(255))
BEGIN
  UPDATE comercios SET logo_url = p_url WHERE comercio_id = p_comercio_id AND borrado = 0;
END$$

DROP PROCEDURE IF EXISTS sp_usuarios_listar$$
CREATE PROCEDURE sp_usuarios_listar(
  IN p_usuario_id BIGINT UNSIGNED, IN p_comercio_id BIGINT UNSIGNED, IN p_rol VARCHAR(30), IN p_estado VARCHAR(20),
  IN p_texto VARCHAR(100), IN p_limite INT, IN p_offset INT)
BEGIN
  SELECT x.*, COUNT(*) OVER () AS total
  FROM (
    SELECT u.usuario_id, u.nombreu, u.nombre, u.email, u.telefono, u.estado, u.ultimo_acceso, u.creado_en,
           (SELECT r.nombre FROM usuario_roles ur JOIN roles r ON r.rol_id = ur.rol_id
             WHERE ur.usuario_id = u.usuario_id AND ur.borrado = 0 AND r.borrado = 0 ORDER BY r.rol_id LIMIT 1) AS rol,
           c.comercio_id, c.nombre AS comercio_nombre
    FROM usuarios u
    LEFT JOIN comercios c ON c.comercio_id = u.comercio_id
    WHERE u.borrado = 0
      AND (IFNULL(p_usuario_id, 0) = 0 OR u.usuario_id = p_usuario_id)
      AND (IFNULL(p_comercio_id, 0) = 0 OR u.comercio_id = p_comercio_id)
      AND (IFNULL(p_estado, '') = '' OR u.estado = p_estado)
      AND (IFNULL(p_texto, '') = '' OR CONCAT_WS(' ', u.nombreu, u.nombre, u.email, c.nombre) LIKE CONCAT('%', p_texto, '%'))
  ) x
  WHERE IFNULL(p_rol, '') = '' OR x.rol = p_rol
  ORDER BY x.estado = 'ACTIVO' DESC, x.nombre, x.nombreu
  LIMIT p_limite OFFSET p_offset;
END$$

DROP PROCEDURE IF EXISTS sp_usuarios_alta$$
CREATE PROCEDURE sp_usuarios_alta(
  IN p_nombreu VARCHAR(100), IN p_password_hash VARCHAR(255), IN p_nombre VARCHAR(150), IN p_email VARCHAR(254),
  IN p_telefono VARCHAR(40), IN p_comercio_id BIGINT UNSIGNED, IN p_rol VARCHAR(30), IN p_asignado_por BIGINT UNSIGNED)
BEGIN
  DECLARE v_rol_id BIGINT UNSIGNED;
  DECLARE v_id BIGINT UNSIGNED;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  SELECT rol_id INTO v_rol_id FROM roles WHERE nombre = p_rol AND borrado = 0;
  IF v_rol_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ROL_INEXISTENTE';
  END IF;
  START TRANSACTION;
  INSERT INTO usuarios (nombreu, password_hash, nombre, email, telefono, comercio_id, estado)
  VALUES (p_nombreu, p_password_hash, p_nombre, NULLIF(p_email, ''), NULLIF(p_telefono, ''), NULLIF(p_comercio_id, 0), 'ACTIVO');
  SET v_id = LAST_INSERT_ID();
  INSERT INTO usuario_roles (usuario_id, rol_id, asignado_por) VALUES (v_id, v_rol_id, p_asignado_por);
  COMMIT;
  SELECT v_id AS usuario_id;
END$$

DROP PROCEDURE IF EXISTS sp_usuarios_editar$$
CREATE PROCEDURE sp_usuarios_editar(
  IN p_usuario_id BIGINT UNSIGNED, IN p_nombre VARCHAR(150), IN p_email VARCHAR(254), IN p_telefono VARCHAR(40),
  IN p_rol VARCHAR(30), IN p_asignado_por BIGINT UNSIGNED)
BEGIN
  DECLARE v_rol_id BIGINT UNSIGNED;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  START TRANSACTION;
  UPDATE usuarios SET nombre = p_nombre, email = NULLIF(p_email, ''), telefono = NULLIF(p_telefono, '')
   WHERE usuario_id = p_usuario_id AND borrado = 0;
  IF IFNULL(p_rol, '') <> '' THEN
    SELECT rol_id INTO v_rol_id FROM roles WHERE nombre = p_rol AND borrado = 0;
    IF v_rol_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ROL_INEXISTENTE';
    END IF;
    UPDATE usuario_roles SET borrado = 1 WHERE usuario_id = p_usuario_id AND rol_id <> v_rol_id;
    INSERT INTO usuario_roles (usuario_id, rol_id, asignado_por) VALUES (p_usuario_id, v_rol_id, p_asignado_por)
    ON DUPLICATE KEY UPDATE borrado = 0, asignado_por = VALUES(asignado_por), asignado_en = CURRENT_TIMESTAMP;
  END IF;
  COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_visitantes_listar$$
CREATE PROCEDURE sp_visitantes_listar(
  IN p_turistas_id INT, IN p_texto VARCHAR(100), IN p_provincia_id BIGINT UNSIGNED, IN p_pais_id BIGINT UNSIGNED,
  IN p_rango_edad_id SMALLINT UNSIGNED, IN p_desde DATE, IN p_hasta DATE, IN p_limite INT, IN p_offset INT)
BEGIN
  SELECT t.turistas_id, CONCAT_WS(' ', t.nombre, t.apellido) AS nombre, t.creado_en, t.ultimo_acceso,
         pr.provincia_id, pr.nombre AS provincia, pa.pais_id, pa.nombre AS pais,
         re.etiqueta AS rango_edad, d.etiqueta AS duracion, m.nombre AS motivo, co.nombre AS compania, r.nombre AS medio,
         (SELECT COUNT(*) FROM cupones cu WHERE cu.turistas_id = t.turistas_id AND cu.borrado = 0) AS cupones_emitidos,
         (SELECT COUNT(*) FROM cupones cu WHERE cu.turistas_id = t.turistas_id AND cu.borrado = 0 AND cu.estado = 'CANJEADO') AS cupones_canjeados,
         COUNT(*) OVER () AS total
  FROM turistas t
  LEFT JOIN encuestas_turisticas e ON e.turistas_id = t.turistas_id AND e.borrado = 0
  LEFT JOIN provincias pr          ON pr.provincia_id = e.provincias_provincia_id
  LEFT JOIN paises pa              ON pa.pais_id = e.pais_procedencia_id
  LEFT JOIN rangos_edad re         ON re.rango_edad_id = e.rango_edad_id
  LEFT JOIN duraciones_estadia d   ON d.duracion_id = e.duracion_id
  LEFT JOIN motivos_visita m       ON m.motivo_id = e.motivos_visita_motivo_id
  LEFT JOIN companias co           ON co.companias_id = e.companias_companias_id
  LEFT JOIN rrss r                 ON r.rrss_id = e.rrss_rrss_id
  WHERE t.borrado = 0
    AND (IFNULL(p_turistas_id, 0) = 0 OR t.turistas_id = p_turistas_id)
    AND (IFNULL(p_provincia_id, 0) = 0 OR e.provincias_provincia_id = p_provincia_id)
    AND (IFNULL(p_pais_id, 0) = 0 OR e.pais_procedencia_id = p_pais_id)
    AND (IFNULL(p_rango_edad_id, 0) = 0 OR e.rango_edad_id = p_rango_edad_id)
    AND (p_desde IS NULL OR t.creado_en >= p_desde)
    AND (p_hasta IS NULL OR t.creado_en < p_hasta + INTERVAL 1 DAY)
    AND (IFNULL(p_texto, '') = '' OR CONCAT_WS(' ', t.nombre, t.apellido, pr.nombre, pa.nombre) LIKE CONCAT('%', p_texto, '%'))
  ORDER BY t.creado_en DESC
  LIMIT p_limite OFFSET p_offset;
END$$

DROP PROCEDURE IF EXISTS sp_reportes$$
CREATE PROCEDURE sp_reportes(
  IN p_desde DATE, IN p_hasta DATE, IN p_comercio_id BIGINT UNSIGNED, IN p_categoria_id SMALLINT UNSIGNED,
  IN p_localidad_id BIGINT UNSIGNED)
BEGIN
  WITH base AS (
    SELECT cu.cupon_id, cu.turistas_id, DATE(cu.emitido_en) AS dia, (cu.estado = 'CANJEADO') AS canjeado,
           v.promocion_id, v.titulo, v.categoria_id, v.categoria_nombre, v.localidad_id, v.localidad_nombre,
           v.comercio_id, v.comercio_nombre
    FROM cupones cu
    JOIN vw_promociones v ON v.promocion_id = cu.promocion_id
    WHERE cu.borrado = 0
      AND cu.emitido_en >= p_desde AND cu.emitido_en < p_hasta + INTERVAL 1 DAY
      AND (IFNULL(p_comercio_id, 0) = 0 OR v.comercio_id = p_comercio_id)
      AND (IFNULL(p_categoria_id, 0) = 0 OR v.categoria_id = p_categoria_id)
      AND (IFNULL(p_localidad_id, 0) = 0 OR v.localidad_id = p_localidad_id)
  ),
  canjes_periodo AS (
    SELECT DATE(ca.canjeado_en) AS dia
    FROM canjes ca
    JOIN cupones cu       ON cu.cupon_id = ca.cupon_id
    JOIN vw_promociones v ON v.promocion_id = cu.promocion_id
    WHERE ca.borrado = 0
      AND ca.canjeado_en >= p_desde AND ca.canjeado_en < p_hasta + INTERVAL 1 DAY
      AND (IFNULL(p_comercio_id, 0) = 0 OR v.comercio_id = p_comercio_id)
      AND (IFNULL(p_categoria_id, 0) = 0 OR v.categoria_id = p_categoria_id)
      AND (IFNULL(p_localidad_id, 0) = 0 OR v.localidad_id = p_localidad_id)
  ),
  visitantes AS (
    SELECT DISTINCT b.turistas_id,
           COALESCE(pr.nombre, pa.nombre, 'Sin responder') AS procedencia,
           COALESCE(re.etiqueta, 'Sin responder') AS rango_edad, COALESCE(d.etiqueta, 'Sin responder') AS duracion,
           COALESCE(m.nombre, 'Sin responder') AS motivo, COALESCE(co.nombre, 'Sin responder') AS compania,
           COALESCE(r.nombre, 'Sin responder') AS medio
    FROM base b
    LEFT JOIN encuestas_turisticas e ON e.turistas_id = b.turistas_id AND e.borrado = 0
    LEFT JOIN provincias pr          ON pr.provincia_id = e.provincias_provincia_id
    LEFT JOIN paises pa              ON pa.pais_id = e.pais_procedencia_id
    LEFT JOIN rangos_edad re         ON re.rango_edad_id = e.rango_edad_id
    LEFT JOIN duraciones_estadia d   ON d.duracion_id = e.duracion_id
    LEFT JOIN motivos_visita m       ON m.motivo_id = e.motivos_visita_motivo_id
    LEFT JOIN companias co           ON co.companias_id = e.companias_companias_id
    LEFT JOIN rrss r                 ON r.rrss_id = e.rrss_rrss_id
  ),
  dias AS (
    SELECT dia FROM base UNION SELECT dia FROM canjes_periodo
  )
  SELECT 'total' AS grupo, NULL AS id, 'visitantes' AS etiqueta, (SELECT COUNT(*) FROM visitantes) AS cantidad, NULL AS canjeados
  UNION ALL SELECT 'total', NULL, 'emitidos', (SELECT COUNT(*) FROM base), NULL
  UNION ALL SELECT 'total', NULL, 'canjeados', (SELECT COUNT(*) FROM canjes_periodo), NULL
  UNION ALL SELECT 'total', NULL, 'promociones_activas',
    (SELECT COUNT(*) FROM vw_promociones v WHERE v.publica = 1
       AND (IFNULL(p_comercio_id, 0) = 0 OR v.comercio_id = p_comercio_id)
       AND (IFNULL(p_categoria_id, 0) = 0 OR v.categoria_id = p_categoria_id)
       AND (IFNULL(p_localidad_id, 0) = 0 OR v.localidad_id = p_localidad_id)), NULL
  UNION ALL SELECT 'total', NULL, 'comercios_activos',
    (SELECT COUNT(*) FROM comercios c WHERE c.borrado = 0 AND c.activo = 1 AND c.estado_autorizacion = 'AUTORIZADO'
       AND (IFNULL(p_comercio_id, 0) = 0 OR c.comercio_id = p_comercio_id)
       AND (IFNULL(p_categoria_id, 0) = 0 OR c.categoria_id = p_categoria_id)
       AND (IFNULL(p_localidad_id, 0) = 0 OR c.localidad_id = p_localidad_id)), NULL
  UNION ALL SELECT 'dia', NULL, DATE_FORMAT(x.dia, '%Y-%m-%d'),
    (SELECT COUNT(*) FROM base b WHERE b.dia = x.dia), (SELECT COUNT(*) FROM canjes_periodo c WHERE c.dia = x.dia)
    FROM dias x
  UNION ALL SELECT 'categoria', categoria_id, MIN(categoria_nombre), COUNT(*), SUM(canjeado) FROM base GROUP BY categoria_id
  UNION ALL SELECT 'localidad', localidad_id, MIN(localidad_nombre), COUNT(*), SUM(canjeado) FROM base GROUP BY localidad_id
  UNION ALL SELECT 'comercio', comercio_id, MIN(comercio_nombre), COUNT(*), SUM(canjeado) FROM base GROUP BY comercio_id
  UNION ALL SELECT 'promocion', promocion_id, MIN(titulo), COUNT(*), SUM(canjeado) FROM base GROUP BY promocion_id
  UNION ALL SELECT 'procedencia', NULL, procedencia, COUNT(*), NULL FROM visitantes GROUP BY procedencia
  UNION ALL SELECT 'rango_edad', NULL, rango_edad, COUNT(*), NULL FROM visitantes GROUP BY rango_edad
  UNION ALL SELECT 'duracion', NULL, duracion, COUNT(*), NULL FROM visitantes GROUP BY duracion
  UNION ALL SELECT 'motivo', NULL, motivo, COUNT(*), NULL FROM visitantes GROUP BY motivo
  UNION ALL SELECT 'compania', NULL, compania, COUNT(*), NULL FROM visitantes GROUP BY compania
  UNION ALL SELECT 'medio', NULL, medio, COUNT(*), NULL FROM visitantes GROUP BY medio
  ORDER BY grupo, cantidad DESC, etiqueta;
END$$

DROP PROCEDURE IF EXISTS sp_actividad_listar$$
CREATE PROCEDURE sp_actividad_listar(
  IN p_comercio_id BIGINT UNSIGNED, IN p_usuario_id BIGINT UNSIGNED, IN p_accion VARCHAR(40),
  IN p_desde DATE, IN p_hasta DATE, IN p_limite INT, IN p_offset INT)
BEGIN
  SELECT a.actividad_id, a.ocurrido_en, a.accion, a.descripcion, a.entidad, a.entidad_id,
         a.usuario_id, COALESCE(u.nombre, u.nombreu) AS usuario_nombre,
         (SELECT r.nombre FROM usuario_roles ur JOIN roles r ON r.rol_id = ur.rol_id
           WHERE ur.usuario_id = a.usuario_id AND ur.borrado = 0 ORDER BY r.rol_id LIMIT 1) AS usuario_rol,
         a.turistas_id, a.comercio_id, c.nombre AS comercio_nombre,
         COUNT(*) OVER () AS total
  FROM actividad a
  LEFT JOIN usuarios u  ON u.usuario_id = a.usuario_id
  LEFT JOIN comercios c ON c.comercio_id = a.comercio_id
  WHERE (IFNULL(p_comercio_id, 0) = 0 OR a.comercio_id = p_comercio_id)
    AND (IFNULL(p_usuario_id, 0) = 0 OR a.usuario_id = p_usuario_id)
    AND (IFNULL(p_accion, '') = '' OR a.accion = p_accion)
    AND (p_desde IS NULL OR a.ocurrido_en >= p_desde)
    AND (p_hasta IS NULL OR a.ocurrido_en < p_hasta + INTERVAL 1 DAY)
  ORDER BY a.ocurrido_en DESC, a.actividad_id DESC
  LIMIT p_limite OFFSET p_offset;
END$$

DROP PROCEDURE IF EXISTS sp_actividad_registrar$$
CREATE PROCEDURE sp_actividad_registrar(
  IN p_accion VARCHAR(40), IN p_descripcion VARCHAR(255), IN p_usuario_id BIGINT UNSIGNED, IN p_turistas_id INT,
  IN p_comercio_id BIGINT UNSIGNED, IN p_entidad VARCHAR(30), IN p_entidad_id BIGINT UNSIGNED, IN p_ip VARCHAR(45))
BEGIN
  INSERT INTO actividad (accion, descripcion, usuario_id, turistas_id, comercio_id, entidad, entidad_id, ip)
  VALUES (p_accion, LEFT(p_descripcion, 255), NULLIF(p_usuario_id, 0), NULLIF(p_turistas_id, 0),
          NULLIF(p_comercio_id, 0), p_entidad, NULLIF(p_entidad_id, 0), p_ip);
END$$

DELIMITER ;
