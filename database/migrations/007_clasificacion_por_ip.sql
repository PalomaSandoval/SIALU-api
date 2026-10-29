-- La clasificación actual se calcula por IP en TrafficIpClassifier.
-- Se conserva catdZonaTrafico para no borrar configuraciones anteriores.
IF OBJECT_ID(N'dbo.vw_ClasificacionTrafico', N'V') IS NOT NULL
    DROP VIEW dbo.vw_ClasificacionTrafico;
