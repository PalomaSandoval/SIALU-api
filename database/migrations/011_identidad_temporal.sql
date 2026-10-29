-- Prevent two assignments of the same normalized address in the same context and time.
CREATE OR ALTER TRIGGER dbo.tr_ActivoIP_Intervalos ON dbo.catdActivoIP AFTER INSERT,UPDATE AS
BEGIN
 SET NOCOUNT ON;
 DECLARE @lock int;
 EXEC @lock=sys.sp_getapplock @Resource='SIALU:network-identities',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @lock<0 THROW 51500,'No se obtuvo bloqueo de identidad de red.',1;
 IF EXISTS(SELECT 1 FROM inserted i JOIN deleted d ON i.IdActivoIP=d.IdActivoIP WHERE i.DireccionIP<>d.DireccionIP AND (i.IPBin=d.IPBin OR i.IPBin IS NULL))
    THROW 51501,'Actualizar una IP requiere su representación binaria validada.',1;
 IF EXISTS(SELECT 1 FROM inserted i JOIN dbo.catdActivoIP b WITH(UPDLOCK,HOLDLOCK)
   ON i.IdActivoIP<>b.IdActivoIP AND ISNULL(i.IdContextoRed,-1)=ISNULL(b.IdContextoRed,-1)
   AND ((i.IPBin IS NOT NULL AND i.IPBin=b.IPBin) OR i.DireccionIP=b.DireccionIP)
   AND i.FechaInicio<COALESCE(b.FechaFin,'9999-12-31') AND b.FechaInicio<COALESCE(i.FechaFin,'9999-12-31'))
    THROW 51502,'La IP tiene asignaciones temporales superpuestas.',1;
END;
GO
CREATE OR ALTER TRIGGER dbo.tr_Identificador_Intervalos ON dbo.catdActivoIdentificadorFuente AFTER INSERT,UPDATE AS
BEGIN
 SET NOCOUNT ON;
 DECLARE @lock int;
 EXEC @lock=sys.sp_getapplock @Resource='SIALU:external-identities',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @lock<0 THROW 51503,'No se obtuvo bloqueo de identificadores.',1;
 IF EXISTS(SELECT 1 FROM inserted i JOIN deleted d ON i.IdActivoIdentificador=d.IdActivoIdentificador
   WHERE i.IdActivo<>d.IdActivo OR i.IdFuente<>d.IdFuente OR i.TipoIdentificador<>d.TipoIdentificador OR i.ValorIdentificador<>d.ValorIdentificador)
   THROW 51504,'La identidad externa es histórica; cierre su intervalo y registre una nueva asociación.',1;
 IF EXISTS(SELECT 1 FROM inserted i JOIN dbo.catdActivoIdentificadorFuente b WITH(UPDLOCK,HOLDLOCK)
   ON i.IdActivoIdentificador<>b.IdActivoIdentificador AND i.IdFuente=b.IdFuente AND i.TipoIdentificador=b.TipoIdentificador AND i.ValorIdentificador=b.ValorIdentificador
   AND COALESCE(i.FechaInicio,'0001-01-01')<COALESCE(b.FechaFin,'9999-12-31') AND COALESCE(b.FechaInicio,'0001-01-01')<COALESCE(i.FechaFin,'9999-12-31'))
   THROW 51505,'El identificador tiene asociaciones temporales superpuestas.',1;
END;
GO
