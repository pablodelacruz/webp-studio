-- ===========================================================================
-- Census of image references. READ-ONLY by construction.
--
-- Analysis only. This script never writes: analysis/q.sh forces
-- SET SESSION TRANSACTION READ ONLY before any statement runs, and the server
-- rejects writes in that session.
--
-- Cost note. A LIKE '%...%' with a leading wildcard cannot use an index, so every
-- query that touches post_content is a full scan of a ~2.2 GB table (~63 s on the
-- measured host). Everything that can be answered in one pass is therefore
-- answered in ONE pass, using string functions instead of per-row parsing.
--
--    ROW_COUNT() - LENGTH(REPLACE(col, needle, '')) / LEN(needle)
--
-- counts occurrences without splitting rows, which avoids a 55,829-row transfer.
-- ===========================================================================

SELECT
    '--- filas con URLs de uploads, por tipo ---' AS seccion;

SELECT
    post_type,
    COUNT(*)                                                        AS filas,
    SUM(
        (LENGTH(post_content) - LENGTH(REPLACE(post_content, 'wp-content/uploads/', ''))) / 19
    )                                                               AS urls_totales,
    ROUND(SUM(
        (LENGTH(post_content) - LENGTH(REPLACE(post_content, 'wp-content/uploads/', ''))) / 19
    ) / COUNT(*), 1)                                                AS urls_por_entrada,
    SUM(
        (LENGTH(post_content) - LENGTH(REPLACE(post_content, '.webp', ''))) / 4
    )                                                               AS urls_webp,
    SUM(
        (LENGTH(post_content) - LENGTH(REPLACE(post_content, '.jpg', ''))) / 4
    )                                                               AS urls_jpg,
    SUM(
        (LENGTH(post_content) - LENGTH(REPLACE(post_content, '.png', ''))) / 4
    )                                                               AS urls_png
FROM np_posts
WHERE post_content LIKE '%wp-content/uploads/%'
GROUP BY post_type
ORDER BY filas DESC;


SELECT
    '--- estado de los adjuntos (indice por tipo, no escanea contenido) ---' AS seccion;

SELECT
    post_mime_type,
    COUNT(*) AS adjuntos
FROM np_posts
WHERE post_type = 'attachment'
GROUP BY post_mime_type
HAVING post_mime_type LIKE 'image/%'
ORDER BY adjuntos DESC;


SELECT
    '--- antiguedad del contenido con imagenes ---' AS seccion;

SELECT
    YEAR(post_date)  AS anio,
    COUNT(*)         AS entradas,
    SUM(
        (LENGTH(post_content) - LENGTH(REPLACE(post_content, 'wp-content/uploads/', ''))) / 19
    )                 AS urls
FROM np_posts
WHERE post_type IN ('post', 'page')
  AND post_status = 'publish'
  AND post_content LIKE '%wp-content/uploads/%'
GROUP BY YEAR(post_date)
ORDER BY anio;


SELECT
    '--- superficies secundarias ---' AS seccion;

SELECT
    'postmeta' AS superficie,
    COUNT(*)   AS filas_con_urls
FROM np_postmeta
WHERE meta_value LIKE '%wp-content/uploads/%'
UNION ALL
SELECT
    'options',
    COUNT(*)
FROM np_options
WHERE option_value LIKE '%wp-content/uploads/%'
UNION ALL
SELECT
    'post_excerpt',
    COUNT(*)
FROM np_posts
WHERE post_excerpt LIKE '%wp-content/uploads/%';


SELECT
    '--- muestra de URLs, para verificar el formato real ---' AS seccion;

SELECT
    post_id,
    SUBSTRING(
        post_content,
        GREATEST(1, LOCATE('wp-content/uploads/', post_content) - 10),
        120
    ) AS fragmento
FROM np_posts
WHERE post_content LIKE '%wp-content/uploads/%'
ORDER BY post_id
LIMIT 10;