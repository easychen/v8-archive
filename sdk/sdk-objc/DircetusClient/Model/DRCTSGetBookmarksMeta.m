#import "DRCTSGetBookmarksMeta.h"

@implementation DRCTSGetBookmarksMeta

- (instancetype)init {
  self = [super init];
  if (self) {
    // initialize property's default value, if any
    
  }
  return self;
}


/**
 * Maps json key to property name.
 * This method is used by `JSONModel`.
 */
+ (JSONKeyMapper *)keyMapper {
  return [[JSONKeyMapper alloc] initWithModelToJSONDictionary:@{ @"type": @"type", @"table": @"table", @"total": @"total", @"delete": @"Delete", @"active": @"Active", @"draft": @"Draft", @"totalEntries": @"total_entries" }];
}

/**
 * Indicates whether the property with the given name is optional.
 * If `propertyName` is optional, then return `YES`, otherwise return `NO`.
 * This method is used by `JSONModel`.
 */
+ (BOOL)propertyIsOptional:(NSString *)propertyName {

  NSArray *optionalProperties = @[@"total", @"delete", @"active", @"draft", @"totalEntries"];
  return [optionalProperties containsObject:propertyName];
}

@end
