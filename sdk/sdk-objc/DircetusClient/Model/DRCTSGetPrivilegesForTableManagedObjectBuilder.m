

#import "DRCTSGetPrivilegesForTableManagedObjectBuilder.h"

/**
* NOTE: This class is auto generated by the swagger code generator program.
* https://github.com/swagger-api/swagger-codegen
* Do not edit the class manually.
*/

@implementation DRCTSGetPrivilegesForTableManagedObjectBuilder

-(instancetype)init {
    self = [super init];
    if (self != nil) {
       _metaBuilder = [[DRCTSGetTablesMetaManagedObjectBuilder alloc] init];
       _dataBuilder = [[DRCTSGetPrivilegesDataManagedObjectBuilder alloc] init];
    }
    return self;
}

-(DRCTSGetPrivilegesForTableManagedObject*)createNewDRCTSGetPrivilegesForTableManagedObjectInContext:(NSManagedObjectContext*)context {
    DRCTSGetPrivilegesForTableManagedObject *managedObject = [NSEntityDescription insertNewObjectForEntityForName:NSStringFromClass([DRCTSGetPrivilegesForTableManagedObject class]) inManagedObjectContext:context];
    return managedObject;
}

-(DRCTSGetPrivilegesForTableManagedObject*)DRCTSGetPrivilegesForTableManagedObjectFromDRCTSGetPrivilegesForTable:(DRCTSGetPrivilegesForTable*)object context:(NSManagedObjectContext*)context {
    DRCTSGetPrivilegesForTableManagedObject* newDRCTSGetPrivilegesForTable = [self createNewDRCTSGetPrivilegesForTableManagedObjectInContext:context];
    [self updateDRCTSGetPrivilegesForTableManagedObject:newDRCTSGetPrivilegesForTable withDRCTSGetPrivilegesForTable:object];
    return newDRCTSGetPrivilegesForTable;
}

-(void)updateDRCTSGetPrivilegesForTableManagedObject:(DRCTSGetPrivilegesForTableManagedObject*)managedObject withDRCTSGetPrivilegesForTable:(DRCTSGetPrivilegesForTable*)object {
    if(!managedObject || !object) {
        return;
    }
    NSManagedObjectContext* context = managedObject.managedObjectContext;

    if(!managedObject.meta) {
        managedObject.meta = [self.metaBuilder DRCTSGetTablesMetaManagedObjectFromDRCTSGetTablesMeta:object.meta context:managedObject.managedObjectContext];
    } else {
        [self.metaBuilder updateDRCTSGetTablesMetaManagedObject:managedObject.meta withDRCTSGetTablesMeta:object.meta];
    }

    if(!managedObject.data) {
        managedObject.data = [self.dataBuilder DRCTSGetPrivilegesDataManagedObjectFromDRCTSGetPrivilegesData:object.data context:managedObject.managedObjectContext];
    } else {
        [self.dataBuilder updateDRCTSGetPrivilegesDataManagedObject:managedObject.data withDRCTSGetPrivilegesData:object.data];
    }

}

-(DRCTSGetPrivilegesForTable*)DRCTSGetPrivilegesForTableFromDRCTSGetPrivilegesForTableManagedObject:(DRCTSGetPrivilegesForTableManagedObject*)obj {
    if(!obj) {
        return nil;
    }
    DRCTSGetPrivilegesForTable* newDRCTSGetPrivilegesForTable = [[DRCTSGetPrivilegesForTable alloc] init];
    [self updateDRCTSGetPrivilegesForTable:newDRCTSGetPrivilegesForTable withDRCTSGetPrivilegesForTableManagedObject:obj];
    return newDRCTSGetPrivilegesForTable;
}

-(void)updateDRCTSGetPrivilegesForTable:(DRCTSGetPrivilegesForTable*)newDRCTSGetPrivilegesForTable withDRCTSGetPrivilegesForTableManagedObject:(DRCTSGetPrivilegesForTableManagedObject*)obj {
    newDRCTSGetPrivilegesForTable.meta = [self.metaBuilder DRCTSGetTablesMetaFromDRCTSGetTablesMetaManagedObject:obj.meta];
    newDRCTSGetPrivilegesForTable.data = [self.dataBuilder DRCTSGetPrivilegesDataFromDRCTSGetPrivilegesDataManagedObject:obj.data];
}

@end