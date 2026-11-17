"""Generate explicit SQL Server catalogue procedures from the reviewed DDL export.

Usage: python database/build_catalog_scripts.py path/to/BD_SIALU.sql
Does not connect to SQL Server. Existing application procedures are excluded.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
TABLES = """catcFuentes catcSeveridad catcAcciones
catcAreas catcCampus catcZonasRed
catdTipoEventos catdActivos catdActivoIP catdRedesInstitucionales
catdActivoIdentificadorFuente catdMapeoSeveridad catdMapeoAccion
catdMapeoTipoEvento catcSentidoFlujo""".split()
ADDED = ['catdMapeoSeveridad', 'catdMapeoAccion', 'catdMapeoTipoEvento',
         'catcSentidoFlujo', 'catdActivoIP']
# Retired by migrations 015 (institutional units), 017 (asset criticality) and 018 (asset types); older DDL exports still contain them.
RETIRED_COLUMNS = {'IdUnidadInstitucional', 'IdCriticidad', 'IdTipoActivo'}


def generate(source):
    raw = Path(source).read_bytes()
    text = raw.decode('utf-16' if raw.startswith((b'\xff\xfe', b'\xfe\xff')) else 'utf-8-sig')
    procedures = []
    migration = []
    preflight = '\n'.join(
        f"IF OBJECT_ID(N'dbo.{table}', N'U') IS NULL\n"
        f"    THROW 50001, 'No existe dbo.{table}; revise la base seleccionada.', 1;"
        for table in TABLES
    )
    for table in ADDED:
        migration.append(f"""IF OBJECT_ID(N'dbo.{table}', N'U') IS NULL
    THROW 50001, 'No existe dbo.{table}.', 1;
IF COL_LENGTH(N'dbo.{table}', N'Vigente') IS NULL
BEGIN
    ALTER TABLE [dbo].[{table}]
        ADD [Vigente] bit NOT NULL
            CONSTRAINT [DF_{table}_Vigente] DEFAULT (1) WITH VALUES;
END;
""")

    for table in TABLES:
        if table in ('catdActivoIP','catdActivoIdentificadorFuente') and (ROOT / 'procedures' / ('sp_' + table + '.sql')).exists():
            # Versioned identity contracts are installed by migrate-catalogs.php, not this legacy bundle.
            continue
        match = re.search(r'CREATE TABLE \[dbo\]\.\[' + table + r'\]\((.*?)(?=^ CONSTRAINT)', text, re.M | re.S)
        if not match:
            raise ValueError(f'Missing table: {table}')
        body = match[1]
        cols = []
        for m in re.finditer(r'^\s*\[(\w+)\] \[(\w+)\](\([^)]*\))?([^\r\n]*)', body, re.M):
            name, kind, size, rest = m.groups()
            if name in RETIRED_COLUMNS:
                continue
            cols.append(dict(name=name, type=kind + (size or ''), identity='IDENTITY' in rest,
                             required='NOT NULL' in rest))
        if table in ADDED and not any(c['name'] == 'Vigente' for c in cols):
            cols.append(dict(name='Vigente', type='bit', identity=False, required=True))
        pkmatch = re.search(r'CONSTRAINT \[[^\]]+\] PRIMARY KEY CLUSTERED\s*\(\s*\[(\w+)\]', text[match.start():], re.S)
        pk = pkmatch[1]
        key = next(c for c in cols if c['name'] == pk)
        fields = [c for c in cols if c['name'] not in (pk, 'Vigente')]
        parameters = [key] + fields + [next(c for c in cols if c['name'] == 'Vigente')]
        params = ',\n'.join('    @' + c['name'] + ' ' + c['type'] + ' = NULL' for c in parameters)
        select = ', '.join('[' + c['name'] + ']' for c in cols)
        validation = []
        defaults = {'FechaAlta': 'SYSUTCDATETIME()', 'FechaInicio': 'SYSUTCDATETIME()',
                    'EsPrincipal': '0', 'ZonaHoraria': "N'UTC'"}
        for c in fields:
            name = c['name']
            if c['type'].startswith(('varchar', 'nvarchar')):
                validation.append(f"        SET @{name} = NULLIF(LTRIM(RTRIM(@{name})), N'');")
            if c['required'] and name not in defaults:
                validation.append(f"        IF @{name} IS NULL\n            THROW 50002, '{name} es obligatorio.', 1;")
            elif c['required'] and name in defaults and name != 'FechaAlta':
                validation.append(f"        IF @Accion = 3 AND @{name} IS NULL\n            THROW 50002, '{name} es obligatorio al actualizar.', 1;")
        if table == 'catdActivos':
            validation.append("        -- FechaAlta es inmutable al actualizar; usar acciones 2/6 para Vigente.")
        if table == 'catdTipoEventos':
            validation.append("        IF @Accion = 3 AND @IdTipoEventoPadre = @IdTipoEvento\n            THROW 50002, 'Un tipo de evento no puede ser su propio padre.', 1;")
        inserted = ([key] if not key['identity'] else []) + fields
        insertcols = ', '.join('[' + c['name'] + ']' for c in inserted) + ', [Vigente]'
        values = []
        for c in inserted:
            name = c['name']
            values.append(f'COALESCE(@{name}, {defaults[name]})' if name in defaults else '@' + name)
        values.append('COALESCE(@Vigente, 1)')
        # FechaAlta is preserved; all other editable fields use full replacement semantics.
        updates = ',\n'.join(f"            [{c['name']}] = @{c['name']}" for c in fields if c['name'] != 'FechaAlta')
        newid = f'CAST(SCOPE_IDENTITY() AS {key["type"]})' if key['identity'] else '@' + pk
        manual = '' if key['identity'] else f"""    IF @Accion = 1 AND @{pk} IS NULL
        THROW 50002, '{pk} es obligatorio: no es IDENTITY.', 1;
"""
        procedure = f"""CREATE OR ALTER PROCEDURE [dbo].[sp_{table}]
    @Accion int,
{params}
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, 'Accion no valida para sp_{table}.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @{pk} IS NULL
        THROW 50002, '{pk} es obligatorio.', 1;
{manual}
    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
{chr(10).join(validation)}
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[{table}] ({insertcols})
        VALUES ({', '.join(values)});

        SELECT {newid} AS [{pk}];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[{table}] SET [Vigente] = 0
        WHERE [{pk}] = @{pk};
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[{table}]
        SET
{updates}
        WHERE [{pk}] = @{pk};
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT {select}
        FROM [dbo].[{table}]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [{pk}];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT {select}
        FROM [dbo].[{table}]
        WHERE [{pk}] = @{pk};
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[{table}] SET [Vigente] = 1
        WHERE [{pk}] = @{pk};
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
"""
        procedures.append(procedure)
        (ROOT / 'procedures' / f'sp_{table}.sql').write_text(
            '-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.\n'
            'SET ANSI_NULLS ON;\nGO\nSET QUOTED_IDENTIFIER ON;\nGO\n' + procedure + 'GO\n', encoding='utf-8')

    migrations = ROOT / 'migrations'
    migrations.mkdir(exist_ok=True)
    change = '\n'.join(migration)
    (migrations / '001_catalogos_vigente.sql').write_text(
        '-- Solo migracion de columnas; tambien incluida en el instalador completo.\n'
        'SET XACT_ABORT ON;\nBEGIN TRY\n    BEGIN TRANSACTION;\n' + change +
        '\n    COMMIT TRANSACTION;\nEND TRY\nBEGIN CATCH\n'
        '    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;\n    THROW;\nEND CATCH;\n', encoding='utf-8')
    install = ['-- Instalador completo: 5 columnas y 18 SP. Ejecutar en la base SIALU.',
               '-- No incluye sp_catcUsuarios ni reemplaza los SP existentes de seguridad.',
               'SET ANSI_NULLS ON;\nSET QUOTED_IDENTIFIER ON;\nSET XACT_ABORT ON;',
               'BEGIN TRY\n    BEGIN TRANSACTION;', preflight, change]
    for procedure in procedures:
        install.append("    EXEC sys.sp_executesql N'" + procedure.replace("'", "''") + "';")
    install.append('    COMMIT TRANSACTION;\nEND TRY\nBEGIN CATCH\n'
                   '    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;\n    THROW;\nEND CATCH;\n')
    (ROOT / 'instalar_catalogos.sql').write_text('\n\n'.join(install), encoding='utf-8')
    print(f'Generated {len(procedures)} procedures and migration for {len(ADDED)} columns.')


if __name__ == '__main__':
    generate(sys.argv[1])
