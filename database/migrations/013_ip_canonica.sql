-- Prevent two assignments of the same normalized address in the same context and time.
CREATE OR ALTER TRIGGER dbo.tr_ActivoIP_Intervalos ON dbo.catdActivoIP AFTER INSERT,UPDATE AS
BEGIN
 SET NOCOUNT ON;
 DECLARE @lock int;
 EXEC @lock=sys.sp_getapplock @Resource='SIALU:network-identities',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @lock<0 THROW 51500,'No se obtuvo bloqueo de identidad de red.',1;
 IF EXISTS(SELECT 1 FROM inserted WHERE IPBin IS NULL OR DATALENGTH(IPBin) NOT IN (4,16))
    THROW 51501,'Se requiere una dirección binaria validada.',1;
 IF EXISTS(SELECT 1 FROM inserted i JOIN dbo.catdActivoIP b WITH(UPDLOCK,HOLDLOCK)
   ON i.IdActivoIP<>b.IdActivoIP AND ISNULL(i.IdContextoRed,-1)=ISNULL(b.IdContextoRed,-1)
   AND ((i.IPBin IS NOT NULL AND i.IPBin=b.IPBin) OR i.DireccionIP=b.DireccionIP)
   AND i.FechaInicio<COALESCE(b.FechaFin,'9999-12-31') AND b.FechaInicio<COALESCE(i.FechaFin,'9999-12-31'))
    THROW 51502,'La IP tiene asignaciones temporales superpuestas.',1;
END;
GO
