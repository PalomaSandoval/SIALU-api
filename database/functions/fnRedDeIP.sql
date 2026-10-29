-- Corrige la referencia a la unidad institucional; conserva el filtro de redes vigentes.
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
           r.IdRedInstitucional, r.Nombre, r.Tipo, r.Zona, r.Campus, r.IdUnidadInstitucional, r.IdCriticidad
    FROM dbo.catdRedesInstitucionales AS r
    WHERE r.Vigente = 1
      AND DATALENGTH(@IPBin)=DATALENGTH(r.RangoInicio)
      AND @IPBin BETWEEN r.RangoInicio AND r.RangoFin
    ORDER BY r.PrefijoCIDR DESC, r.IdRedInstitucional ASC
);
GO
