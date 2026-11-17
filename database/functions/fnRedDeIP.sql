-- Red vigente más específica que contiene la IP; sin unidad institucional (migración 015) ni criticidad (migración 017).
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER FUNCTION [dbo].[fnRedDeIP] (@IPBin varbinary(16))
RETURNS TABLE
AS
RETURN
(
    SELECT TOP (1)
           r.IdRedInstitucional, r.Nombre, r.Tipo, r.Zona, r.Campus
    FROM dbo.catdRedesInstitucionales AS r
    WHERE r.Vigente = 1
      AND DATALENGTH(@IPBin)=DATALENGTH(r.RangoInicio)
      AND @IPBin BETWEEN r.RangoInicio AND r.RangoFin
    ORDER BY r.PrefijoCIDR DESC, r.IdRedInstitucional ASC
);
GO
